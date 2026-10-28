<?php

namespace Tests\Unit;

use App\Http\Controllers\V1\Admin\SubscriptionRuleController;
use App\Services\AiRiskService;
use PHPUnit\Framework\TestCase;

class IpMaskCompatibilityTest extends TestCase
{
    public function testCompressedIpv6NeverRetainsHostBits(): void
    {
        $cases = [
            '2001:db8::1' => '2001:db8::/64',
            '2001:0db8:0000:0000:0000:0000:0000:0001' => '2001:db8::/64',
            '2001:db8:abcd:1234:9876:5432:1111:2222' => '2001:db8:abcd:1234::/64',
            '2001:DB8:ABCD::1' => '2001:db8:abcd::/64',
            '::1' => '::/64',
            '::ffff:192.0.2.99' => '::/64',
            ' 192.0.2.99 ' => '192.0.2.0/24',
            '' => '',
        ];
        foreach ([new AiRiskService(), new SubscriptionRuleController()] as $target) {
            $method = new \ReflectionMethod($target, 'maskIp');
            foreach ($cases as $ip => $expected) {
                $masked = $method->invoke($target, $ip);
                $this->assertSame($expected, $masked, get_class($target) . ': ' . $ip);
                if (str_contains($masked, '/64')) {
                    [$network] = explode('/', $masked);
                    $this->assertSame(str_repeat("\0", 8), substr(inet_pton($network), 8));
                }
            }
        }
    }

    public function testMultibyteTruncationPreservesUnicode(): void
    {
        foreach ([new AiRiskService(), new SubscriptionRuleController()] as $target) {
            $method = new \ReflectionMethod($target, 'trimText');
            $input = " \u{6D4B}\u{8BD5}\u{1F680}abc ";
            $this->assertSame("\u{6D4B}\u{8BD5}\u{1F680}", $method->invoke($target, $input, 3));
            $this->assertSame('', $method->invoke($target, $input, 0));
            $this->assertSame('invalid-ip', (new \ReflectionMethod($target, 'maskIp'))->invoke($target, ' invalid-ip '));
        }
    }
}
