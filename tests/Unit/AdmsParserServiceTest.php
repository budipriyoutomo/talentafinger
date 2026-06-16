<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\AdmsParserService;

class AdmsParserServiceTest extends TestCase
{
    private AdmsParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new AdmsParserService();
    }

    public function test_parses_valid_attlog_payload(): void
    {
        $payload = "ATTLOG\tSN=ABC123\t2026-06-15 08:03:22\t1234\t0\t1\t0";

        $result = $this->parser->parse($payload);

        $this->assertNotNull($result);
        $this->assertEquals('ABC123', $result['sn']);
        $this->assertEquals('2026-06-15 08:03:22', $result['timestamp']);
        $this->assertEquals('1234', $result['biometric_id']);
        $this->assertEquals('0', $result['status']);
        $this->assertEquals('1', $result['verify']);
        $this->assertEquals('0', $result['work_code']);
    }

    public function test_returns_null_for_malformed_payload(): void
    {
        $this->assertNull($this->parser->parse("INVALID\tDATA"));
    }

    public function test_returns_null_for_empty_payload(): void
    {
        $this->assertNull($this->parser->parse(''));
    }

    public function test_returns_null_for_non_attlog_line(): void
    {
        $payload = "OPLOG\tSN=ABC123\t2026-06-15 08:03:22\t1234\t0\t1\t0";

        $this->assertNull($this->parser->parse($payload));
    }

    public function test_parses_first_valid_line_from_multiline_payload(): void
    {
        $payload = "\n"
            . "ATTLOG\tSN=ABC123\t2026-06-15 08:03:22\t1234\t0\t1\t0\n"
            . "ATTLOG\tSN=ABC123\t2026-06-15 09:00:00\t5678\t0\t1\t0";

        $result = $this->parser->parse($payload);

        $this->assertNotNull($result);
        $this->assertEquals('1234', $result['biometric_id']);
    }
}
