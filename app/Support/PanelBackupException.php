<?php

namespace App\Support;

use RuntimeException;

/**
 * Carries a stable message key plus structured error data out of
 * PanelBackup::restore(), so the controller can turn it into the same
 * Api::error(message, errors, 422) shape the rest of the installer uses.
 */
class PanelBackupException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errors
     */
    public function __construct(string $message, private readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
