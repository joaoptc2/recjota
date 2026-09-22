<?php

declare(strict_types=1);

namespace App\Services\Integrations\Instagram;

use RuntimeException;

/**
 * O orçamento de tempo de InstagramClient::withTimeBudget() acabou antes da
 * próxima chamada. Interno ao cliente: sai dele como InstagramApiException de
 * rede (transitória), com a mensagem acionável de sempre.
 */
final class BudgetExhausted extends RuntimeException {}
