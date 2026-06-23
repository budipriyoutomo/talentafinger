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

    public function test_parses_valid_attlog_line(): void
    {
        // Format body iClock: PIN \t DateTime \t Status \t Verify \t WorkCode.
        // SN TIDAK ada di body (dikirim via query string).
        $payload = "1234\t2026-06-15 08:03:22\t0\t1\t0";

        $result = $this->parser->parse($payload);

        $this->assertCount(1, $result);
        $this->assertEquals('1234', $result[0]['biometric_id']);
        $this->assertEquals('2026-06-15 08:03:22', $result[0]['timestamp']);
        $this->assertEquals('0', $result[0]['status']);
        $this->assertEquals('1', $result[0]['verify']);
        $this->assertEquals('0', $result[0]['work_code']);
    }

    public function test_parses_multiple_lines(): void
    {
        $payload = "1234\t2026-06-15 08:03:22\t0\t1\t0\n"
            . "5678\t2026-06-15 09:00:00\t0\t1\t0";

        $result = $this->parser->parse($payload);

        $this->assertCount(2, $result);
        $this->assertEquals('1234', $result[0]['biometric_id']);
        $this->assertEquals('5678', $result[1]['biometric_id']);
    }

    public function test_returns_empty_for_empty_payload(): void
    {
        $this->assertSame([], $this->parser->parse(''));
    }

    public function test_skips_lines_without_pin_and_timestamp(): void
    {
        // Hanya satu kolom -> tidak cukup (butuh minimal PIN + timestamp).
        $this->assertSame([], $this->parser->parse('INVALID'));
    }

    public function test_parse_fingerprints_extracts_template_lines(): void
    {
        $payload = "USER PIN=1\tName=Budi\n"
            . "FP PIN=1\tFID=3\tSize=1640\tValid=1\tTMP=QkFTRTY0\n"
            . "OPLOG something";

        $result = $this->parser->parseFingerprints($payload);

        $this->assertCount(1, $result);
        $this->assertEquals('1', $result[0]['biometric_id']);
        $this->assertEquals(3, $result[0]['fid']);
        $this->assertEquals(1640, $result[0]['size']);
        $this->assertEquals(1, $result[0]['valid']);
        $this->assertEquals('QkFTRTY0', $result[0]['template']);
    }
}
