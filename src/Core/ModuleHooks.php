<?php
declare(strict_types=1);

namespace Quasar\Core;

/**
 * Lógica propia de un módulo. Se declara en el JSON con "hooks": "Clase\\Completa".
 * Los métodos que devuelven ?string devuelven una clave de idioma de error para abortar.
 */
abstract class ModuleHooks
{
    /** @var array<string,mixed> valores validados del formulario que se guarda, incluidos los campos "virtual" */
    protected array $values = [];

    public function __construct(protected readonly Database $db)
    {
    }

    /** Lo llama FormHandler antes de beforeSave(): los hooks pueden leer campos virtuales en $this->values. */
    public function setFormValues(array $values): void
    {
        $this->values = $values;
    }

    /**
     * @param array<string,mixed> $data columnas => valores que se van a guardar (modificable)
     * @param array<string,mixed>|null $existing registro actual (null si es nuevo)
     */
    public function beforeSave(array &$data, ?string $id, ?array $existing, User $user): ?string
    {
        return null;
    }

    public function afterSave(string $id, array $data, bool $isNew, User $user): void
    {
    }

    public function beforeDelete(string $id, User $user): ?string
    {
        return null;
    }

    public function afterDelete(string $id, User $user): void
    {
    }

    /**
     * Usuarios a los que avisar de un mensaje nuevo en la conversación del registro, además de los
     * de "chat.notify", los que ya han escrito y los mencionados. Solo se avisa a quien puede verlo.
     * @param array<string,mixed> $row el registro (valores del formulario)
     * @return list<int> ids de usuario
     */
    public function chatRecipients(string $id, array $row, User $author): array
    {
        return [];
    }
}
