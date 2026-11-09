<?php declare(strict_types=1);

namespace App\GraphQL\Directives;

use App\Models\User;
use Closure;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\TypeExtensionNode;
use GraphQL\Type\Definition\ResolveInfo;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Nuwave\Lighthouse\Schema\AST\ASTHelper;
use Nuwave\Lighthouse\Schema\AST\DocumentAST;
use Nuwave\Lighthouse\Schema\Directives\BaseDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\FieldMiddleware;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Nuwave\Lighthouse\Support\Contracts\TypeExtensionManipulator;
use Nuwave\Lighthouse\Support\Contracts\TypeManipulator;

/**
 * Requires the current user to hold ANY of the listed permissions.
 *
 * Declared as `@permission(require: ["product.update"])`. When `require` is
 * omitted it falls back to plain staff access (any role), which keeps the
 * existing model level declarations working while the finer grained
 * permissions are added to individual fields.
 *
 * Accepted on OBJECT as well as FIELD_DEFINITION because LaraQL attaches the
 * `directives` of a #[Model]/#[Query]/#[Mutation] to the generated extension
 * object; the type manipulators below copy it down to each field, exactly like
 * Lighthouse does for `@guard`.
 */
class PermissionDirective extends BaseDirective implements FieldMiddleware, TypeManipulator, TypeExtensionManipulator
{
    public static function definition(): string
    {
        return /** @lang GraphQL */ <<<'GRAPHQL'
"""
Only allow the field when the user holds at least one of the given permissions.
"""
directive @permission(
  """
  Permission names, e.g. ["product.update"]. At least one must be held.
  When omitted, any staff member (a user with a role) is allowed.
  """
  require: [String!]
) repeatable on FIELD_DEFINITION | OBJECT
GRAPHQL;
    }

    public function handleField(FieldValue $fieldValue): void
    {
        $fieldValue->wrapResolver(fn (callable $resolver): Closure => function (
            mixed $root,
            array $args,
            GraphQLContext $context,
            ResolveInfo $resolveInfo,
        ) use ($resolver) {
            /** @var User|null $user */
            $user = $context->user() ?? auth()->user();

            if (! $user) {
                throw new AuthorizationException('You are not allowed to perform this action.');
            }

            $required = (array) ($this->directiveArgValue('require') ?? []);

            $allowed = $required === []
                ? $user->roles()->exists() || $user->isAdmin()
                : collect($required)->contains(fn (string $permission): bool => $user->can($permission));

            if (! $allowed) {
                throw new AuthorizationException('You are not allowed to perform this action.');
            }

            return $resolver($root, $args, $context, $resolveInfo);
        });
    }

    public function manipulateTypeDefinition(DocumentAST &$documentAST, TypeDefinitionNode &$typeDefinition): void
    {
        ASTHelper::addDirectiveToFields($this->directiveNode, $typeDefinition); // @phpstan-ignore argument.type
    }

    public function manipulateTypeExtension(DocumentAST &$documentAST, TypeExtensionNode &$typeExtension): void
    {
        ASTHelper::addDirectiveToFields($this->directiveNode, $typeExtension); // @phpstan-ignore argument.type
    }
}
