<?php declare(strict_types=1);

namespace App\GraphQL\Mutations;

use App\GraphQL\Support\AuthorizesAdmin;
use App\Models\Color;
use App\Models\Size;
use App\Models\Volume;
use GraphQL\Error\UserError;
use Nodesol\LaraQL\Attributes\Mutation;
use Nodesol\LaraQL\Attributes\Type;

/**
 * Admin only: create or update one value in a master variant list.
 *
 * `kind` selects the list: color, size or volume. One mutation covers all
 * three so the admin panel has a single code path.
 */
#[Type(
    columns: [
        'id' => 'ID!',
        'kind' => 'String!',
        'label' => 'String!',
        'hex' => 'String',
        'ml' => 'Float',
    ]
)]
#[Mutation(
    name: ' ',
    query: '@field(resolver:"SaveVariantMaster") @guard @permission(require: ["product.create", "product.update"])',
    inputs: [
        'kind' => 'String! @rules(apply:["required","in:color,size,volume"])',
        'id' => 'ID',
        'label' => 'String! @rules(apply:["required","max:60"])',
        'hex' => 'String @rules(apply:["max:9"])',
        'ml' => 'Float @rules(apply:["nullable","numeric","min:0"])',
        'sort_order' => 'Int! = 0',
        'is_active' => 'Boolean! = true',
    ],
)]
class SaveVariantMaster
{
    use AuthorizesAdmin;

    /** @param array{} $args */
    public function __invoke($root, array $args)
    {
        $kind = (string) $args['kind'];
        $label = trim((string) $args['label']);

        $model = match ($kind) {
            'color' => Color::class,
            'size' => Size::class,
            'volume' => Volume::class,
        };

        /** @var \Illuminate\Database\Eloquent\Model|null $record */
        $record = isset($args['id']) ? $model::find((int) $args['id']) : new $model();

        if (! $record) {
            throw new UserError('That value could not be found.');
        }

        $nameField = $args['kind'] == 'volume' ? 'label' : 'name';

        // Labels must be unique within their list.
        $taken = $model::query()
            ->where($nameField, $label)
            ->when($record->exists, fn ($query) => $query->whereKeyNot($record->getKey()))
            ->exists();

        if ($taken) {
            throw new UserError('That value already exists in the list.');
        }

        $payload = [
            'sort_order' => (int) ($args['sort_order'] ?? 0),
            'is_active' => (bool) ($args['is_active'] ?? true),
        ];

        if ($kind === 'color') {
            $payload['name'] = $label;
            $payload['hex_code'] = $args['hex'] ?? null;
        } elseif ($kind === 'size') {
            $payload['name'] = $label;
        } else {
            $payload['label'] = $label;
            $payload['ml'] = (float) ($args['ml'] ?? 0);
        }

        $record->fill($payload)->save();

        return [
            'id' => $record->getKey(),
            'kind' => $kind,
            'label' => $kind === 'volume' ? $record->label : $record->name,
            'hex' => $kind === 'color' ? $record->hex_code : null,
            'ml' => $kind === 'volume' ? (float) $record->ml : null,
        ];
    }
}
