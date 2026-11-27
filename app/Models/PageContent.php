<?php

namespace App\Models;

use App\Services\MediaService;
use Illuminate\Database\Eloquent\Model;

/**
 * The editable opening block of one storefront page.
 *
 * Every marketing page — home, the three category pages, about, contact, cart
 * and checkout — opens with the same shape: a badge, a title, a subtitle, a main
 * picture and one call to action. Storing it per page means an administrator can
 * change the picture a page opens with from the panel, and the storefront never
 * hard codes artwork again.
 *
 * The table only ever holds rows that were actually edited. `defaults()` is the
 * copy the storefront falls back to, so a fresh install still renders every page
 * before the first save.
 */
class PageContent extends Model
{
    protected $fillable = [
        'page',
        'badge',
        'title',
        'subtitle',
        'hero_image',
        'cta_label',
        'cta_url',
    ];

    /**
     * The storefront pages that have an editable opening block.
     *
     * `hero_image` is what ships when the shop has never been edited; the admin
     * panel shows it as the placeholder so the first save is a replace, not a
     * guess.
     *
     * The two catalogue pages (`clothing`, `fragrances`) are deliberately absent:
     * they open straight into the filter sidebar and the product grid, so there
     * is no hero to replace and the admin editor lists no row for them.
     *
     * @var array<string, array<string, string>>
     */
    public const PAGES = [
        'home' => [
            'badge' => 'New season · 2026',
            'title' => 'Style Redefined',
            'subtitle' => 'Fashion and Fragrance Under One Roof.',
            'hero_image' => '',
            'cta_label' => 'Shop Now',
            'cta_url' => '/fragrances',
        ],
        'about' => [
            'badge' => 'Our story',
            'title' => 'ARSH Wear & Fragrance',
            'subtitle' => 'Fashion. Fragrance. Confidence. ARSH Wear & Fragrance is a premium fashion and fragrance house built on timeless design, quality products and unmatched style and elegance.',
            'hero_image' => '',
            'cta_label' => 'Our Journey',
            'cta_url' => '/contact',
        ],
        'contact' => [
            'badge' => 'Say hello',
            'title' => 'Get In Touch With Us',
            'subtitle' => 'We would love to hear from you.',
            'hero_image' => '',
            'cta_label' => 'Contact Us',
            'cta_url' => '/contact',
        ],
        'cart' => [
            'badge' => 'Your selection',
            'title' => 'Your Shopping Cart',
            'subtitle' => 'Review the pieces you picked before checking out.',
            'hero_image' => '',
            'cta_label' => '',
            'cta_url' => '',
        ],
        'checkout' => [
            'badge' => 'Final step',
            'title' => 'Checkout Page',
            'subtitle' => 'Confirm your delivery details and place the order.',
            'hero_image' => '',
            'cta_label' => '',
            'cta_url' => '',
        ],
        'faqs' => [
            'badge' => 'Support',
            'title' => 'Frequently asked questions',
            'subtitle' => 'Everything about ordering, shipping and caring for your pieces.',
            'hero_image' => '',
            'cta_label' => '',
            'cta_url' => '',
        ],
    ];

    /**
     * The default copy for a page, or a blank page shape for an unknown key.
     *
     * @return array<string, string>
     */
    public static function defaults(string $page): array
    {
        return self::PAGES[$page] ?? [
            'badge' => '',
            'title' => ucfirst($page),
            'subtitle' => '',
            'hero_image' => '',
            'cta_label' => '',
            'cta_url' => '',
        ];
    }

    /** The page keys the storefront can ask for, in the panel's order. */
    public static function pages(): array
    {
        return array_keys(self::PAGES);
    }

    /**
     * Absolute URL of the hero picture.
     *
     * Resolved through MediaService so a path saved on the local disk, on S3 or
     * an absolute CDN URL all produce a usable `src`.
     */
    public function getHeroImageUrlAttribute(): string
    {
        return app(MediaService::class)->url($this->getRawOriginal('hero_image'));
    }

    /**
     * Keep the bucket clean when a page picture is replaced or removed.
     *
     * The row stores one picture, so any other value means the previous file is
     * now orphaned: it is deleted from whatever disk is configured (the Oracle
     * Cloud bucket in production, the local public disk in development).
     *
     * The upload flow re-sends the *same* path when nothing changed, so a plain
     * "save the title" click never deletes the picture still in use.
     */
    protected static function booted(): void
    {
        static::updating(function (PageContent $page): void {
            if (! $page->isDirty('hero_image')) {
                return;
            }

            $previous = $page->getRawOriginal('hero_image');
            $next = $page->getAttributes()['hero_image'] ?? null;

            if ($previous !== null && $previous !== '' && $previous !== $next) {
                app(MediaService::class)->forget($previous);
            }
        });

        // Removing the row (or the last picture) must not leave the object
        // behind either.
        static::deleting(function (PageContent $page): void {
            app(MediaService::class)->forget($page->getRawOriginal('hero_image'));
        });
    }
}
