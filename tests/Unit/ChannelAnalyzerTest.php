<?php

use LaraMint\LaravelBrain\Analysis\ChannelAnalyzer;
use LaraMint\LaravelBrain\Analysis\ChannelDefinition;

/** @param string[] $registrars */
function channelNames(array $registrars = []): array
{
    $channels = (new ChannelAnalyzer(['packages/*/routes/channels.php'], $registrars))
        ->analyze(fixture('modular-project'));

    return array_map(fn (ChannelDefinition $channel): string => $channel->name, $channels);
}

it('finds channels registered through the Broadcast facade', function () {
    expect(channelNames())->toContain('orders.{orderId}');
});

it('ignores a wrapper class that was not configured as a registrar', function () {
    expect(channelNames())->not->toContain('tenant.orders.{orderId}');
});

it('finds channels registered through a configured registrar', function () {
    // An application that wraps the facade — to scope channels to a tenant, say —
    // registers every channel through its own class and none through Broadcast.
    expect(channelNames(['TenantChannel']))->toContain('tenant.orders.{orderId}');
});

it('accepts a registrar written as a fully qualified class name', function () {
    expect(channelNames(['Acme\\Shop\\Broadcasting\\TenantChannel']))
        ->toContain('tenant.orders.{orderId}');
});

it('keeps recognising the Broadcast facade when registrars are configured', function () {
    expect(channelNames(['TenantChannel']))->toContain('orders.{orderId}');
});
