<?php

namespace Mad\Support;

/**
 * Mapas UF → nome do estado e UF → código IBGE da unidade federativa.
 *
 * DUPLICAÇÃO INTENCIONAL de App\Support\BrazilianStates do madbuilder-backend:
 * o framework não pode depender da plataforma, e este dado é imutável (os
 * códigos IBGE de UF não mudam desde 1988). Vive como `const` — não é config
 * (não varia por ambiente) nem banco (1 query por lookup pra 27 linhas fixas).
 *
 * Alimenta o _deriveLocation do MadCnpjService: o lookup de CNPJ devolve só a
 * sigla (`uf`), mas a resolução de cidade/estado (MadLocationResolver) e o
 * fill-fields esperam `estado` (nome por extenso) e `estado_cod_ibge` — o mesmo
 * contrato do lookup de CEP.
 *
 * NUNCA lança: UF desconhecida devolve string vazia. O fill-fields trata
 * ausência como campo vazio — quebrar aqui derrubaria a consulta inteira por
 * causa de um campo secundário.
 */
final class BrazilianStates
{
    private const NAMES = [
        'AC' => 'Acre',
        'AL' => 'Alagoas',
        'AP' => 'Amapá',
        'AM' => 'Amazonas',
        'BA' => 'Bahia',
        'CE' => 'Ceará',
        'DF' => 'Distrito Federal',
        'ES' => 'Espírito Santo',
        'GO' => 'Goiás',
        'MA' => 'Maranhão',
        'MT' => 'Mato Grosso',
        'MS' => 'Mato Grosso do Sul',
        'MG' => 'Minas Gerais',
        'PA' => 'Pará',
        'PB' => 'Paraíba',
        'PR' => 'Paraná',
        'PE' => 'Pernambuco',
        'PI' => 'Piauí',
        'RJ' => 'Rio de Janeiro',
        'RN' => 'Rio Grande do Norte',
        'RS' => 'Rio Grande do Sul',
        'RO' => 'Rondônia',
        'RR' => 'Roraima',
        'SC' => 'Santa Catarina',
        'SP' => 'São Paulo',
        'SE' => 'Sergipe',
        'TO' => 'Tocantins',
    ];

    private const IBGE = [
        'AC' => '12',
        'AL' => '27',
        'AP' => '16',
        'AM' => '13',
        'BA' => '29',
        'CE' => '23',
        'DF' => '53',
        'ES' => '32',
        'GO' => '52',
        'MA' => '21',
        'MT' => '51',
        'MS' => '50',
        'MG' => '31',
        'PA' => '15',
        'PB' => '25',
        'PR' => '41',
        'PE' => '26',
        'PI' => '22',
        'RJ' => '33',
        'RN' => '24',
        'RS' => '43',
        'RO' => '11',
        'RR' => '14',
        'SC' => '42',
        'SP' => '35',
        'SE' => '28',
        'TO' => '17',
    ];

    /** Nome por extenso do estado. '' quando a UF não é reconhecida. */
    public static function name(?string $uf): string
    {
        return self::NAMES[self::normalize($uf)] ?? '';
    }

    /** Código IBGE da UF (2 dígitos, string). '' quando não reconhecida. */
    public static function ibge(?string $uf): string
    {
        return self::IBGE[self::normalize($uf)] ?? '';
    }

    public static function isValid(?string $uf): bool
    {
        return isset(self::NAMES[self::normalize($uf)]);
    }

    /** @return array<string, string> UF => nome */
    public static function all(): array
    {
        return self::NAMES;
    }

    private static function normalize(?string $uf): string
    {
        return strtoupper(trim((string) $uf));
    }
}
