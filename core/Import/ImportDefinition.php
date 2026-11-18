<?php

declare(strict_types=1);

namespace Blue\Core\Import;

use Blue\Core\Authz\Authorizer;

/**
 * One importable data set (6.10 item 1, item 3 "bulk update"). The ImportService owns the common steps — template,
 * upload, type check, column mapping, preview, error report, confirm, audit; a definition only knows its columns,
 * how to validate one row, and how to apply valid rows (inside one transaction, through the owning service).
 */
interface ImportDefinition
{
    /** e.g. "calendar.days" */
    public function code(): string;

    /** Template version written in the template file name and stored on the batch, e.g. "v1". */
    public function version(): string;

    /** Translation key of the title shown on the import pages. */
    public function titleKey(): string;

    /**
     * Columns in template order. key = machine name (also accepted as header), label = translation key,
     * example = sample value written in the template's instructions sheet.
     *
     * @return list<array{key: string, label: string, required: bool, example: string, help?: string}>
     */
    public function columns(): array;

    /**
     * Checks the caller may import into the target (e.g. this calendar) and returns normalized parameters.
     * Throws HttpException 403/404 otherwise.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    public function prepare(Authorizer $az, array $params): array;

    /**
     * Validates one row (keys = column keys). $seen lets a definition detect duplicates inside the file.
     *
     * @param array<string, string> $row
     * @param array<string, mixed> $params
     * @param array<string, int> $seen
     * @return array{data: array<string, mixed>, errors: array<string, string>, action: 'create'|'update'|'none'}
     */
    public function validateRow(array $row, array $params, array &$seen): array;

    /**
     * Applies the valid rows (already inside a transaction). Returns the number of records written.
     *
     * @param array<string, mixed> $params
     * @param list<array<string, mixed>> $rows data of valid rows
     */
    public function apply(Authorizer $az, array $params, array $rows, int $batchId): int;

    /**
     * Columns whose value is shown masked (last 4 characters) on the preview, e.g. bank account numbers.
     *
     * @return list<string>
     */
    public function sensitiveColumns(): array;

    /**
     * Where to go back to after the import (relative path).
     *
     * @param array<string, mixed> $params
     */
    public function backLink(array $params): string;
}
