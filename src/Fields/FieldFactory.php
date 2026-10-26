<?php
declare(strict_types=1);

namespace Quasar\Fields;

use Quasar\Core\FieldContext;

/**
 * Registro de tipos de campo. Para añadir uno propio crea una clase que
 * extienda Field y regístrala en config.php ('field_types') o con register().
 */
final class FieldFactory
{
    /** @var array<string,class-string<Field>> */
    private static array $types = [
        'text' => TextField::class,
        'email' => EmailField::class,
        'password' => PasswordField::class,
        'number' => NumberField::class,
        'textarea' => TextareaField::class,
        'select' => SelectField::class,
        'checkbox' => CheckboxField::class,
        'date' => DateField::class,
        'datetime' => DateTimeField::class,
        'hidden' => HiddenField::class,
        'repeater' => RepeaterField::class,
        'media' => MediaField::class,
        'tags' => TagsField::class,
        'duration' => DurationField::class,
        'richtext' => RichTextField::class,
    ];

    public static function register(string $type, string $class): void
    {
        if (!is_subclass_of($class, Field::class)) {
            throw new \InvalidArgumentException("$class debe extender " . Field::class);
        }
        self::$types[$type] = $class;
    }

    public static function make(string $name, array $def, FieldContext $ctx): Field
    {
        $type = (string) ($def['type'] ?? 'text');
        $class = self::$types[$type] ?? null;
        if ($class === null) {
            throw new \InvalidArgumentException("Tipo de campo desconocido '$type' en el campo '$name'");
        }
        return new $class($name, $def, $ctx);
    }
}
