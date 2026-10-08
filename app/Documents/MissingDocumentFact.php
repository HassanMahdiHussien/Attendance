<?php

namespace App\Documents;

use App\Exceptions\IssuanceBlocked;

class MissingDocumentFact extends IssuanceBlocked
{
    public function __construct(string $fact)
    {
        parent::__construct("بيان المستند غير موجود: {$fact}.");
    }
}
