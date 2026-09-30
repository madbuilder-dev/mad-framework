<?php
namespace Mad\Filters;

/**
 * Calendar interval with strict ISO/BR input and an exclusive upper bound.
 *
 * Aceita a data pura ('2025-11-01', '01/11/2025', '01-11-2025') e as formas que
 * sessao/valor default/mascara legada realmente carregam: 'Y-m-d H:i:s' e
 * 'Y-m-d\TH:i:s' com fracao de segundo e Z/offset opcionais. A hora e' truncada
 * (a data e' lida como escrita, sem conversao de fuso). Qualquer outra coisa —
 * data solta ('1/1/2025'), separador trocado, mes/dia impossivel, ano 0000 —
 * continua sendo recusada.
 */
final class MadDateRange
{
    /** Data ISO com hora opcional; Z/offset so' na forma com 'T'. */
    private const ISO_RE = '/^(\d{4}-\d{2}-\d{2})(?:[ ](?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?|T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3]):?[0-5]\d)?)?$/D';

    /** Data BR com o MESMO separador nas duas posicoes ('01/11/2025' | '01-11-2025'). */
    private const BR_RE = '/^(\d{2})([\/-])(\d{2})\2(\d{4})$/D';

    private function __construct(
        private readonly \DateTimeImmutable $start,
        private readonly \DateTimeImmutable $end,
    ) {}

    public static function fromInput(?string $start, ?string $end): ?self
    {
        $start = trim($start ?? '');
        $end = trim($end ?? '');
        if ($start === '' && $end === '') return null;
        $a = self::parse($start);
        $b = self::parse($end);
        if ($a > $b) throw new \InvalidArgumentException('A data inicial deve ser anterior ou igual à data final.');
        return new self($a, $b);
    }

    private static function parse(string $value): \DateTimeImmutable
    {
        $date = null;
        if (preg_match(self::ISO_RE, $value, $m)) {
            $date = self::exactDate('Y-m-d', $m[1]);
        } elseif (preg_match(self::BR_RE, $value, $m)) {
            $date = self::exactDate('d/m/Y', $m[1] . '/' . $m[3] . '/' . $m[4]);
        }
        if ($date !== null) return $date;
        throw new \InvalidArgumentException('Informe duas datas válidas no formato AAAA-MM-DD ou DD/MM/AAAA (a hora, se houver, é ignorada).');
    }

    /** Data exata: recusa overflow (31/02), ano 0 e qualquer divergencia de mascara. */
    private static function exactDate(string $format, string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!' . $format, $value, new \DateTimeZone('UTC'));
        if ($date === false) return null;
        $errors = \DateTimeImmutable::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) return null;
        if ($date->format($format) !== $value) return null;
        return (int) $date->format('Y') > 0 ? $date : null;
    }

    /** @return array{0:string,1:string} [inclusive start, exclusive end] */
    public function bounds(): array
    {
        return [$this->start->format('Y-m-d'), $this->end->modify('+1 day')->format('Y-m-d')];
    }

    public function previous(): self
    {
        $days = (int) $this->start->diff($this->end)->format('%a') + 1;
        return new self($this->start->modify('-' . $days . ' days'), $this->start->modify('-1 day'));
    }
}
