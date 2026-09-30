<?php

namespace App\Support;

use App\Models\Instructor;

class ProfileDiff
{
    /** Date fields whose cast original must be formatted before comparing to the submitted string. */
    private const DATE_FIELDS = ['civil_id_expires_on', 'degree_obtained_on'];

    /**
     * Names (sorted) of the validated fields whose value differs from the stored one. Never values.
     * Encrypted attributes always report dirty (ciphertext differs per set), so the list is computed
     * from the decrypted originals before fill(), never from getDirty() after.
     *
     * @return list<string>
     */
    public static function changedFields(Instructor $instructor, array $validated): array
    {
        $changed = [];
        foreach ($validated as $field => $value) {
            $original = $instructor->getOriginal($field);
            $originalValue = in_array($field, self::DATE_FIELDS, true) ? optional($original)->format('Y-m-d') : $original;
            if ((string) ($originalValue ?? '') !== (string) ($value ?? '')) {
                $changed[] = $field;
            }
        }
        sort($changed);

        return $changed;
    }
}
