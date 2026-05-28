<?php
declare(strict_types=1);
namespace App\Tests\Service\Library;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
/**
 * Validates VDA-ISA 6 cross-framework mapping YAMLs extracted from ENX workbook.
 */
final class VdaIsaCrossFrameworkMappingTest extends TestCase
{
    private function dir(): string { return __DIR__.'/../../../fixtures/library/mappings'; }
    public static function crossFrameworkMappingProvider(): array {
        return [
            'ISA/IEC 62443' => ['tisax-vda-isa-6_to_iec-isa-62443_v1.0.yaml', 20, '/^\d+\.\d+(\.\d+)?$/'],
            'NIST CSF 1.1'  => ['tisax-vda-isa-6_to_nist-csf-1.1_v1.0.yaml',  25, '/^[A-Z]{2,3}[.\-][A-Z]{2,4}-\d+$/'],
            'ISO 27017'     => ['tisax-vda-isa-6_to_iso27017_v1.0.yaml',       3,  '/^(CLD\.\d+\.\d+(\.\d+)?|\d+\.\d+\.\d+)$/'],
            'ISO 27002'     => ['tisax-vda-isa-6_to_iso27002_v1.0.yaml',       1,  '/^A\.\d+\.\d+(\.\d+)?$/'],
        ];
    }
    #[Test] #[DataProvider('crossFrameworkMappingProvider')]
    public function mappingYamlParsesCleanly(string $f, int $min, string $pat): void {
        self::assertFileExists($this->dir().'/'.$f, "Not found: $f");
        self::assertIsArray(Yaml::parseFile($this->dir().'/'.$f));
    }
    #[Test] #[DataProvider('crossFrameworkMappingProvider')]
    public function mappingYamlHasRequiredSchemaFields(string $f, int $min, string $pat): void {
        $d=Yaml::parseFile($this->dir().'/'.$f);
        self::assertArrayHasKey('schema_version',$d); self::assertArrayHasKey('library',$d); self::assertArrayHasKey('mappings',$d);
        self::assertSame('mapping',$d['library']['type']); self::assertNotEmpty($d['library']['source_framework']); self::assertNotEmpty($d['library']['target_framework']); self::assertArrayHasKey('provenance',$d['library']);
    }
    #[Test] #[DataProvider('crossFrameworkMappingProvider')]
    public function mappingYamlMeetsMinimumEntryCount(string $f, int $min, string $pat): void {
        $d=Yaml::parseFile($this->dir().'/'.$f);
        self::assertGreaterThanOrEqual($min,count($d['mappings']),"$f: expected >= $min, got ".count($d['mappings']));
    }
    #[Test] #[DataProvider('crossFrameworkMappingProvider')]
    public function allMappingsHaveRequiredFields(string $f, int $min, string $pat): void {
        $d=Yaml::parseFile($this->dir().'/'.$f);
        foreach($d['mappings'] as $i=>$m){
            self::assertArrayHasKey('source',$m,"[$i]:missing source"); self::assertArrayHasKey('targets',$m,"[$i]:missing targets");
            self::assertArrayHasKey('relationship',$m,"[$i]:missing relationship"); self::assertArrayHasKey('confidence',$m,"[$i]:missing confidence");
            self::assertIsArray($m['targets'],"[$i]:targets not array"); self::assertNotEmpty($m['targets'],"[$i]:targets empty");
        }
    }
    #[Test] #[DataProvider('crossFrameworkMappingProvider')]
    public function sourceIdsMatchVdaIsaPattern(string $f, int $min, string $pat): void {
        $d=Yaml::parseFile($this->dir().'/'.$f);
        foreach($d['mappings'] as $i=>$m){ self::assertMatchesRegularExpression('/^ISA \d+\.\d+(\.\d+)?$/',$m['source'],"[$i]:'{$m['source']}' not ISA X.Y.Z"); }
    }
    #[Test] #[DataProvider('crossFrameworkMappingProvider')]
    public function sourceIdsAreUniquePerFile(string $f, int $min, string $pat): void {
        $d=Yaml::parseFile($this->dir().'/'.$f);
        $s=array_column($d['mappings'],'source'); $dup=array_diff_assoc($s,array_unique($s));
        self::assertEmpty($dup,"$f: duplicates: ".implode(',',$dup));
    }
    #[Test]
    public function extractionScriptProducesConsistentOutput(): void {
        $root=__DIR__.'/../../..'; $script=$root.'/scripts/import/extract_vda_isa_all_mappings.php'; $fix=$root.'/tests/Fixtures/vda_isa_6_de_official.xlsx';
        self::assertFileExists($script,'Script not found');
        if (!file_exists($fix)) { self::markTestSkipped('ENX workbook fixture not present (removed by Nextcloud sync — safe to skip in CI).'); }
        $tmp=sys_get_temp_dir().'/vda_x_'.uniqid(); mkdir($tmp,0777,true);
        try {
            $out=(string)shell_exec('php '.escapeshellarg($script).' '.escapeshellarg($fix).' '.escapeshellarg($tmp).' 2>&1');
            self::assertStringContainsString('iec-isa-62443',$out); self::assertStringContainsString('nist-csf-1.1',$out); self::assertStringContainsString('Done.',$out);
            $gen=glob($tmp.'/*.yaml')??[]; self::assertGreaterThanOrEqual(3,count($gen));
            foreach($gen as $yf){$d=Yaml::parseFile($yf);self::assertArrayHasKey('mappings',$d);self::assertNotEmpty($d['mappings']);}
        } finally { foreach(glob($tmp.'/*.yaml')??[] as $yf){unlink($yf);} if(is_dir($tmp))rmdir($tmp); }
    }
}
