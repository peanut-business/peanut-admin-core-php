<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Model;

use PeanutAdmin\Kernel\Persistence\Model\TenantModel;

final class FileDeliveryNonceRecord extends TenantModel
{
    /** @var string */ protected $name = 'file_delivery_nonce';
    /** @var string */ protected $pk = 'token_id_hash';
}
