<?php

namespace App\Support;

use RuntimeException;

/** İmza anahtarı yok/bozuk: istemci uçları imzasız belge dönmez, 503 ile kapanır. */
class SigningUnavailableException extends RuntimeException
{
}
