<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport\Persistence\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class ImportExportRowErrorRecord extends TenantModel
{
    /** @var string */ protected $name = 'import_export_row_error';
    /** @var array<string, string> */ protected $type = [
        'id' => 'integer', 'tenant_id' => 'integer', 'operation_id' => 'integer', 'row_number' => 'integer',
    ];
}
