<?php

namespace Tests\Feature;

use App\Models\PageContent;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nuwave\Lighthouse\Testing\MakesGraphQLRequests;
use Tests\CreatesCatalog;
use Tests\CreatesStaffUsers;
use Tests\TestCase;

/**
 * Editable storefront page heroes (the "main pic" of every page) and the
 * merchandising audience a product is filed under.
 */
class PageHeroTest extends TestCase
{
    use CreatesCatalog;
    use CreatesStaffUsers;
    use MakesGraphQLRequests;
    use RefreshDatabase;

    public function test_a_page_hero_falls_back_to_its_shipped_copy(): void
    {
        $this->graphQL(/** @lang GraphQL */ '
            query {
                PageHero(page: "home") { page title subtitle hero_image hero_image_url }
            }
        ')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.PageHero.page', 'home')
            ->assertJsonPath('data.PageHero.title', PageContent::PAGES['home']['title'])
            ->assertJsonPath('data.PageHero.hero_image', null);
    }

    public function test_an_admin_can_change_a_page_picture_and_copy(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->graphQL(/** @lang GraphQL */ '
            mutation {
                SavePageHero(
                    page: "home"
                    title: "Style Redefined"
                    subtitle: "Fashion and Fragrance Under One Roof."
                    hero_image: "pages/home.jpg"
                    cta_label: "Shop Now"
                    cta_url: "/shop"
                ) {
                    page title subtitle hero_image hero_image_url cta_label cta_url
                }
            }
        ')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.SavePageHero.hero_image', 'pages/home.jpg');

        // The storefront reads the same row the panel just wrote.
        $this->graphQL(/** @lang GraphQL */ '
            query {
                PageHero(page: "home") { title hero_image hero_image_url }
            }
        ')
            ->assertJsonPath('data.PageHero.title', 'Style Redefined')
            ->assertJsonPath('data.PageHero.hero_image', 'pages/home.jpg')
            ->assertJsonPath('data.PageHero.hero_image_url', fn (string $url): bool => str_ends_with($url, 'pages/home.jpg'));
    }

    public function test_saving_only_the_picture_keeps_the_shipped_title(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->graphQL(/** @lang GraphQL */ '
            mutation {
                SavePageHero(page: "about", hero_image: "pages/about.jpg") { title }
            }
        ')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.SavePageHero.title', PageContent::PAGES['about']['title']);
    }

    /**
     * The catalogue pages open straight into the filter sidebar and product
     * grid, so they have no hero row to edit any more.
     */
    public function test_the_catalogue_pages_have_no_hero(): void
    {
        $this->assertArrayNotHasKey('clothing', PageContent::PAGES);
        $this->assertArrayNotHasKey('fragrances', PageContent::PAGES);
    }

    public function test_only_staff_can_edit_a_page_hero(): void
    {
        $customer = $this->seedCustomer();

        $this->actingAs($customer)->graphQL(/** @lang GraphQL */ '
            mutation {
                SavePageHero(page: "home", title: "Hijacked") { title }
            }
        ')->assertGraphQLErrorMessage('You are not allowed to perform this action.');
    }

    public function test_an_unknown_page_is_rejected(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->graphQL(/** @lang GraphQL */ '
            mutation {
                SavePageHero(page: "no-such-page", title: "Nope") { title }
            }
        ')->assertGraphQLErrorMessage('There is no "no-such-page" page to edit.');
    }

    public function test_the_admin_editor_lists_every_page(): void
    {
        $admin = $this->adminUser();

        $this->actingAs($admin)->graphQL(/** @lang GraphQL */ '
            query {
                AdminPageHeroes { page title hero_image_url }
            }
        ')
            ->assertJsonMissingPath('errors')
            ->assertJsonCount(count(PageContent::pages()), 'data.AdminPageHeroes');
    }

    public function test_a_product_can_be_filed_under_an_audience(): void
    {
        $product = $this->clothingProduct(['audience' => Product::AUDIENCE_WOMEN]);

        $this->assertSame('women', $product->fresh()->audience);

        $this->graphQL(/** @lang GraphQL */ '
            query {
                BrowseProducts(audience: "women") { data { name } }
                men: BrowseProducts(audience: "men") { data { name } }
            }
        ')
            ->assertJsonMissingPath('errors')
            ->assertJsonPath('data.BrowseProducts.data.0.name', $product->name)
            ->assertJsonCount(0, 'data.men.data');
    }

    public function test_an_unknown_audience_is_stored_as_unisex(): void
    {
        $product = $this->clothingProduct(['audience' => 'not-a-real-audience']);

        $this->assertSame(Product::AUDIENCE_UNISEX, $product->fresh()->audience);
    }

    protected function seedCustomer(): User
    {
        $this->seedPermissions();

        return User::factory()->create(['is_admin' => false]);
    }
}
