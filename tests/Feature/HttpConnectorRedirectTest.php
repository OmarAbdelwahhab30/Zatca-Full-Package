<?php

namespace Zatca\Tests\Feature;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Zatca\Exceptions\ZatcaContractChangedException;
use Zatca\Services\Connectors\ConnectorFactory;
use Zatca\Tests\Concerns\BuildsProductionInvoices;
use Zatca\Tests\TestCase;

/**
 * Every authenticated call carries the CSID token and secret as Basic auth. ZATCA's API
 * never redirects these POSTs, so a redirect must stop the call instead of replaying the
 * credentials at whatever address it names.
 */
class HttpConnectorRedirectTest extends TestCase
{
    use BuildsProductionInvoices;

    public function test_a_redirect_is_not_followed(): void
    {
        $this->seedFakeProductionCredentials();

        $history = [];
        $zatca = new MockHandler([
            new Response(302, ['Location' => 'https://elsewhere.example/steal']),
            new Response(200, [], '{"validationResults":{"status":"PASS"}}'),
        ]);
        $stack = HandlerStack::create($zatca);
        $stack->push(Middleware::history($history));
        $this->app->instance(Client::class, new Client(['handler' => $stack]));

        try {
            app(ConnectorFactory::class)->make()->reportInvoice('default', 'HASH', 'uuid', base64_encode('<Invoice/>'));
            $this->fail('A redirect response is not a valid ZATCA answer.');
        } catch (ZatcaContractChangedException) {
        }

        $this->assertCount(1, $history, 'Only the original request may be sent.');
        $this->assertCount(1, $zatca, 'The redirect target must never be requested.');
    }
}
