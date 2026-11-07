<?php
namespace axenox\GenAI\Tests\PHPUnit\Unit\Common;

use axenox\GenAI\Common\AiPayload;
use PHPUnit\Framework\TestCase;

class AiPayloadTest extends TestCase
{
    public function testRetainsJsonValuesAndNestedObjectIdentity(): void
    {
        $payload = [
            'id' => '00123',
            'exponent' => '1e3',
            'decimal' => 1.0,
            'flag' => false,
            'missing' => null,
            'text' => 'Grüße / "quoted"',
            'empty_object' => new \stdClass(),
            'empty_array' => [],
            'arguments' => (object) [
                '0' => 'zero',
                'list' => [1, null, (object) ['code' => '0007']]
            ]
        ];

        $json = AiPayload::encode($payload);
        $restored = AiPayload::decode($json);

        $this->assertEquals($payload, $restored);
        $this->assertSame('00123', $restored['id']);
        $this->assertSame('1e3', $restored['exponent']);
        $this->assertSame(1.0, $restored['decimal']);
        $this->assertInstanceOf(\stdClass::class, $restored['empty_object']);
        $this->assertSame([], $restored['empty_array']);
        $this->assertInstanceOf(\stdClass::class, $restored['arguments']);
        $this->assertInstanceOf(\stdClass::class, $restored['arguments']->list[2]);
        $this->assertSame($json, AiPayload::encode($restored));
    }

    public function testAcceptsObjectRootAndListRoot(): void
    {
        $this->assertSame(['id' => '001'], AiPayload::decode(AiPayload::encode((object) ['id' => '001'])));
        $this->assertSame(['001', 1, false, null], AiPayload::decode('["001",1,false,null]'));
        $this->assertSame([], AiPayload::decode('{}'));
        $this->assertSame([], AiPayload::decode('[]'));
    }

    public function testInvalidJsonThrowsExplicitJsonError(): void
    {
        $this->expectException(\JsonException::class);
        AiPayload::decode('{"unfinished":');
    }

    public function testInvalidUtf8ThrowsExplicitJsonError(): void
    {
        $this->expectException(\JsonException::class);
        AiPayload::encode(['value' => "\xB1\x31"]);
    }

    public function testRecursivePayloadThrowsExplicitJsonError(): void
    {
        $payload = new \stdClass();
        $payload->self = $payload;
        $this->expectException(\JsonException::class);
        AiPayload::encode($payload);
    }

    public function testNonFiniteNumberThrowsExplicitJsonError(): void
    {
        $this->expectException(\JsonException::class);
        AiPayload::encode(['value' => INF]);
    }

    public function testScalarRootIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        AiPayload::decode('"not an object"');
    }
}
