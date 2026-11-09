<?php

namespace Tests\Unit;

use App\Support\Numbering;
use PHPUnit\Framework\TestCase;

class NumberingTest extends TestCase
{
    public function test_every_document_type_has_a_non_empty_prefix_except_kot(): void
    {
        foreach (Numbering::PREFIXES as $type => $prefix) {
            if ($type === 'kot') {
                $this->assertSame('', $prefix, 'KOT numbers print bare, by design');
                continue;
            }
            $this->assertNotSame('', $prefix, "Document type {$type} must have a prefix");
            $this->assertMatchesRegularExpression('/^[A-Z0-9]+$/', $prefix, "Prefix for {$type} must be uppercase alphanumeric");
        }
    }

    public function test_prefix_table_covers_the_core_documents(): void
    {
        foreach (['reservation' => 'RES', 'folio' => 'FOL', 'invoice' => 'INV', 'pos_bill' => 'BIL', 'po' => 'PO'] as $type => $expected) {
            $this->assertSame($expected, Numbering::PREFIXES[$type] ?? null);
        }
    }

    public function test_document_types_are_unique_keys(): void
    {
        $types = array_keys(Numbering::PREFIXES);
        $this->assertSame(count($types), count(array_unique($types)));
    }
}
