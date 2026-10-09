<?php

namespace Tests\Unit;

use App\Services\MobileMoneyGateway;
use PHPUnit\Framework\TestCase;

class MobileMoneyFailureCodeTest extends TestCase
{
    public function test_operator_reasons_map_to_codes_the_app_explains(): void
    {
        // Motif réel ElgioPay : numéro Orange envoyé chez MTN.
        $this->assertSame('wrong_network', MobileMoneyGateway::failureCode('The phone number is not registered on this mobile money network.'));
        $this->assertSame('insufficient_funds', MobileMoneyGateway::failureCode('Insufficient balance'));
        $this->assertSame('expired', MobileMoneyGateway::failureCode('Paiement expiré (non validé à temps)'));
        $this->assertSame('declined', MobileMoneyGateway::failureCode('Transaction cancelled by user'));
        $this->assertNull(MobileMoneyGateway::failureCode('Error indicating that the connector could not find the transaction'));
        $this->assertNull(MobileMoneyGateway::failureCode(null));
    }
}
