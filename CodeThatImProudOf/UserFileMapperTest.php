<?php

namespace Tests\Unit;

use App\FileImport;
use App\SIA\Mappers\UserFileMapper;
use App\SIA\Parsers\UserFileParser;
use Illuminate\Support\Collection;
use Tests\TestCase;

class UserFileMapperTest extends TestCase
{
    /** @var UserFileMapper */
    private $mapper;

    private $fileImportMock;

    public function testParseDate(): void
    {
        $date = $this->mapper->parseDate('16022021');

        $this->assertInstanceOf(\Carbon\Carbon::class, $date);
    }

    public function testMap(): UserFileMapper
    {
        $data = $this->mapper->map($this->fileImportMock);

        $this->assertInstanceOf(UserFileMapper::class, $data);

        return $data;
    }

    /**
     * @depends testMap
     *
     */
    public function testFilterEnsuringReturnOne(UserFileMapper $mapper): array
    {
        /** @var Collection */
        $lines = $mapper->filter(function ($line) {
            return $line['user']['chave'] === 'F1672512';
        });

        /** @var array */
        $line = $lines->first();

        $this->assertCount(1, $lines);

        return $line;
    }

    /**
     * @depends testFilterEnsuringReturnOne
     *
     * @return void
     */
    public function testFilterReturnCorrectValue(array $line)
    {
        $this->assertArrayHasKey('user', $line);
        $this->assertArrayHasKey('course', $line);

        $this->assertIsArray($line['course']);
        $this->assertIsArray($line['user']);

        $this->assertArrayHasKey('chave', $line['user']);
        $this->assertArrayHasKey('name', $line['user']);
        $this->assertArrayHasKey('cpf', $line['user']);
        $this->assertArrayHasKey('email', $line['user']);
        $this->assertArrayHasKey('username', $line['user']);
        $this->assertArrayHasKey('profile_id', $line['user']);
        $this->assertArrayHasKey('expired_at', $line['user']);

        $this->assertArrayHasKey('id', $line['course']);
        $this->assertArrayHasKey('expired_at', $line['course']);

        $this->assertEquals($line['user']['chave'], 'F1672512');
        $this->assertEquals($line['user']['name'], 'BRAITNY SOARES FERREIRA DE SALES');
        $this->assertEquals($line['user']['cpf'], '07274367676');
        $this->assertEquals($line['user']['email'], 'f1672512@bb.com.br');
        $this->assertEquals($line['user']['username'], 'F1672512');
        $this->assertNull($line['user']['profile_id']);
        $this->assertInstanceOf(\Carbon\Carbon::class, $line['user']['expired_at']);
        $this->assertEquals($line['user']['expired_at']->format('d-m-Y H:i:s'), '04-02-2021 23:59:59');

        $this->assertEquals($line['course']['id'], 572);
        $this->assertInstanceOf(\Carbon\Carbon::class, $line['course']['expired_at']);
        $this->assertEquals($line['course']['expired_at']->format('d-m-Y H:i:s'), '04-02-2021 23:59:59');
    }

    /**
     * @depends testMap
     *
     * @return void
     */
    public function testWhereUserEnsuringReturnOne(UserFileMapper $mapper)
    {
        $this->assertCount(1, $mapper->whereUser('cpf', '07274367676'));
    }

    /**
     * @depends testMap
     *
     * @return void
     */
    public function testWhereUserEnsuringReturnNothing(UserFileMapper $mapper)
    {
        $this->assertCount(0, $mapper->whereUser('cpf', '11111111111'));
    }

    /**
     * @depends testMap
     *
     * @return void
     */
    public function testParseEnsuringReturnOne(UserFileMapper $mapper)
    {
        $lines = $mapper->parse($this->fileImportMock);

        $this->assertCount(1, $lines);

        return $lines;
    }

    /**
     * @depends testParseEnsuringReturnOne
     *
     * @return void
     */
    public function testParseEnsuringReturnCorrectValue(Collection $lines)
    {
        $line = $lines->first();

        $this->assertEquals($line, [
            'TIPO_USUARIO' => '1',
            'CHAVE' => 'F1672512',
            'CPF' => '07274367676',
            'EMAIL' => 'f1672512@bb.com.br',
            'NOME' => 'BRAITNY SOARES FERREIRA DE SALES',
            'SITUA' => '100',
            'CATEG' => '01123',
            'SEXO' => 'M',
            'DT_NASC' => '03101989',
            'GRAU_INSTR' => '02',
            'DT_ENTRADA' => '23022015',
            'CARGO' => '00610',
            'EST_CIVIL' => '02',
            'SECAO' => '899900',
            'PREFIXO' => '00215',
            'UOR_DEPE' => '000002388',
            'TIPOLOGIA_MST' => '3000',
            'TIPOLOGIA_NOVO_MST' => '0054',
            'UF' => 'MG',
            'GEPES_JURISD' => '09228',
            'UOR_GEPES' => '000470980',
            'DIRETORIA_SUBORDINANTE' => '09270',
            'UOR_DIRET_SUBORDINANTE' => '000464250',
            'CURSO_ATUALIZACAO' => '00000572',
            'DATA_DE_EXPIRACAO_DO' => '04022021',
        ]);
    }

    private function makeFileImportMock()
    {
        $fileImportMock = $this->createMock(FileImport::class);
        $fileImportMock->method('map')
                       ->willReturn(collect([
                           [
                               'TIPO_USUARIO' => '1',
                               'CHAVE' => 'F1672512',
                               'CPF' => '07274367676',
                               'EMAIL' => 'f1672512@bb.com.br',
                               'NOME' => 'BRAITNY SOARES FERREIRA DE SALES',
                               'SITUA' => '100',
                               'CATEG' => '01123',
                               'SEXO' => 'M',
                               'DT_NASC' => '03101989',
                               'GRAU_INSTR' => '02',
                               'DT_ENTRADA' => '23022015',
                               'CARGO' => '00610',
                               'EST_CIVIL' => '02',
                               'SECAO' => '899900',
                               'PREFIXO' => '00215',
                               'UOR_DEPE' => '000002388',
                               'TIPOLOGIA_MST' => '3000',
                               'TIPOLOGIA_NOVO_MST' => '0054',
                               'UF' => 'MG',
                               'GEPES_JURISD' => '09228',
                               'UOR_GEPES' => '000470980',
                               'DIRETORIA_SUBORDINANTE' => '09270',
                               'UOR_DIRET_SUBORDINANTE' => '000464250',
                               'CURSO_ATUALIZACAO' => '00000572',
                               'DATA_DE_EXPIRACAO_DO' => '04022021',
                           ],
                       ]));

        return $fileImportMock;
    }

    protected function setUp(): void
    {
        $parserMock = $this->createMock(UserFileParser::class);
        $parserMock->method('parseFields')
                    ->willReturn([
                        'TIPO_USUARIO' => '1',
                        'CHAVE' => 'F1672512',
                        'CPF' => '07274367676',
                        'EMAIL' => 'f1672512@bb.com.br',
                        'NOME' => 'BRAITNY SOARES FERREIRA DE SALES',
                        'SITUA' => '100',
                        'CATEG' => '01123',
                        'SEXO' => 'M',
                        'DT_NASC' => '03101989',
                        'GRAU_INSTR' => '02',
                        'DT_ENTRADA' => '23022015',
                        'CARGO' => '00610',
                        'EST_CIVIL' => '02',
                        'SECAO' => '899900',
                        'PREFIXO' => '00215',
                        'UOR_DEPE' => '000002388',
                        'TIPOLOGIA_MST' => '3000',
                        'TIPOLOGIA_NOVO_MST' => '0054',
                        'UF' => 'MG',
                        'GEPES_JURISD' => '09228',
                        'UOR_GEPES' => '000470980',
                        'DIRETORIA_SUBORDINANTE' => '09270',
                        'UOR_DIRET_SUBORDINANTE' => '000464250',
                        'CURSO_ATUALIZACAO' => '00000572',
                        'DATA_DE_EXPIRACAO_DO' => '04022021',
                    ]);

        $this->mapper = new UserFileMapper($parserMock);
        $this->fileImportMock = $this->makeFileImportMock();

        parent::setUp();
    }
}
