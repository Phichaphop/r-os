<?php

declare(strict_types=1);

namespace Blue\Core\Pdf;

/**
 * Standard security handler (ISO 32000-1 §7.6.3, ISO 32000-2 §7.6.4): opens files protected with RC4 40/128-bit,
 * AES-128 (R2–R4) and AES-256 (R5/R6) when the person gives the password; protects new files with AES-256 (R6).
 * There is no password guessing — a wrong password is simply refused (S01 6.24.2 item 11).
 */
final class Security
{
    private const PAD = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    /** permission bits (ISO 32000 Table 22) */
    public const PERM_PRINT = 4;
    public const PERM_MODIFY = 8;
    public const PERM_COPY = 16;
    public const PERM_ANNOTATE = 32;
    public const PERM_FILL = 256;
    public const PERM_ACCESSIBILITY = 512;
    public const PERM_ASSEMBLE = 1024;
    public const PERM_PRINT_HQ = 2048;

    private function __construct(
        private readonly string $key,
        private readonly int $revision,
        private readonly string $streamMethod, // RC4 | AESV2 | AESV3 | None
        private readonly string $stringMethod,
        public readonly bool $encryptMetadata,
        public readonly bool $ownerAuth,
    ) {
    }

    // ---- opening ----------------------------------------------------------------------------------

    public static function open(Dict $enc, string $firstId, string $password): self
    {
        if ($enc->name('Filter') !== 'Standard') {
            throw new PdfError('pdf.error.encryption_unsupported', ['method' => (string) $enc->name('Filter')]);
        }
        $v = (int) ($enc->get('V') ?? 0);
        $r = (int) ($enc->get('R') ?? 0);
        $o = self::bytes($enc->get('O'));
        $u = self::bytes($enc->get('U'));
        $p = (int) ($enc->get('P') ?? 0);
        $encMeta = $enc->get('EncryptMetadata') !== false;
        [$stm, $str] = self::methods($enc, $v);
        if ($r >= 5) {
            $oe = self::bytes($enc->get('OE'));
            $ue = self::bytes($enc->get('UE'));
            $pw = substr(self::saslPrep($password), 0, 127);
            $o = str_pad($o, 48, "\0");
            $u = str_pad($u, 48, "\0");
            if (hash_equals(substr($o, 0, 32), self::hashR6($pw, substr($o, 32, 8), substr($u, 0, 48), $r))) {
                $ik = self::hashR6($pw, substr($o, 40, 8), substr($u, 0, 48), $r);
                $key = (string) openssl_decrypt($oe, 'aes-256-cbc', $ik, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
                return new self($key, $r, $stm, $str, $encMeta, true);
            }
            if (hash_equals(substr($u, 0, 32), self::hashR6($pw, substr($u, 32, 8), '', $r))) {
                $ik = self::hashR6($pw, substr($u, 40, 8), '', $r);
                $key = (string) openssl_decrypt($ue, 'aes-256-cbc', $ik, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
                return new self($key, $r, $stm, $str, $encMeta, false);
            }
            throw new PdfError('pdf.error.password');
        }
        if ($r < 2) {
            throw new PdfError('pdf.error.encryption_unsupported', ['method' => 'R' . $r]);
        }
        $len = $r === 2 ? 5 : intdiv((int) ($enc->get('Length') ?? 40), 8);
        if ($v === 4) {
            $len = 16;
        }
        $len = max(5, min(16, $len));
        $pw = self::latin($password);
        // user password
        $key = self::keyR4($pw, $o, $p, $firstId, $r, $len, $encMeta);
        if (self::checkUser($key, $u, $firstId, $r)) {
            return new self($key, $r, $stm, $str, $encMeta, false);
        }
        // owner password → recover the user password from /O
        $h = substr(str_pad($pw, 32, self::PAD), 0, 32);
        $h = md5($h, true);
        if ($r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $h = md5(substr($h, 0, $len), true);
            }
        }
        $ok = substr($h, 0, $r === 2 ? 5 : $len);
        $userPw = $o;
        if ($r === 2) {
            $userPw = self::rc4($ok, $userPw);
        } else {
            for ($i = 19; $i >= 0; $i--) {
                $k = '';
                for ($j = 0, $n = strlen($ok); $j < $n; $j++) {
                    $k .= chr(ord($ok[$j]) ^ $i);
                }
                $userPw = self::rc4($k, $userPw);
            }
        }
        $key = self::keyR4($userPw, $o, $p, $firstId, $r, $len, $encMeta, true);
        if (self::checkUser($key, $u, $firstId, $r)) {
            return new self($key, $r, $stm, $str, $encMeta, true);
        }
        throw new PdfError('pdf.error.password');
    }

    public function decryptString(int $num, int $gen, string $data): string
    {
        return $this->crypt($num, $gen, $data, $this->stringMethod, false);
    }

    public function decryptStream(int $num, int $gen, string $data): string
    {
        return $this->crypt($num, $gen, $data, $this->streamMethod, false);
    }

    public function encryptString(int $num, int $gen, string $data): string
    {
        return $this->crypt($num, $gen, $data, $this->stringMethod, true);
    }

    public function encryptStream(int $num, int $gen, string $data): string
    {
        return $this->crypt($num, $gen, $data, $this->streamMethod, true);
    }

    public function method(): string
    {
        return $this->revision >= 5 ? 'AES-256' : ($this->streamMethod === 'AESV2' ? 'AES-128' : 'RC4 ' . (strlen($this->key) * 8) . '-bit');
    }

    // ---- protecting (AES-256, R6) ----------------------------------------------------------------------

    /** @return array{0: Dict, 1: self} the /Encrypt dictionary and an encryptor for the writer */
    public static function createR6(string $userPassword, string $ownerPassword, int $permissions): array
    {
        $upw = substr(self::saslPrep($userPassword), 0, 127);
        $opw = substr(self::saslPrep($ownerPassword !== '' ? $ownerPassword : bin2hex(random_bytes(16))), 0, 127);
        $fileKey = random_bytes(32);
        $uvs = random_bytes(8);
        $uks = random_bytes(8);
        $u = self::hashR6($upw, $uvs, '', 6) . $uvs . $uks;
        $ue = (string) openssl_encrypt($fileKey, 'aes-256-cbc', self::hashR6($upw, $uks, '', 6), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
        $ovs = random_bytes(8);
        $oks = random_bytes(8);
        $o = self::hashR6($opw, $ovs, $u, 6) . $ovs . $oks;
        $oe = (string) openssl_encrypt($fileKey, 'aes-256-cbc', self::hashR6($opw, $oks, $u, 6), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
        $p = (int) (0xFFFFF0C0 | ($permissions & 0xF3C)) ; // reserved bits set, bits 1–2 clear
        $pSigned = $p > 0x7FFFFFFF ? $p - 0x100000000 : $p;
        $perms = (string) openssl_encrypt(pack('V', $p & 0xFFFFFFFF) . "\xFF\xFF\xFF\xFF" . 'Tadb' . random_bytes(4), 'aes-256-ecb', $fileKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        $dict = new Dict([
            'Filter' => new Name('Standard'), 'V' => 5, 'R' => 6, 'Length' => 256,
            'CF' => new Dict(['StdCF' => new Dict(['AuthEvent' => new Name('DocOpen'), 'CFM' => new Name('AESV3'), 'Length' => 32])]),
            'StmF' => new Name('StdCF'), 'StrF' => new Name('StdCF'),
            'O' => new Str($o, true), 'U' => new Str($u, true), 'OE' => new Str($oe, true), 'UE' => new Str($ue, true),
            'Perms' => new Str($perms, true), 'P' => $pSigned, 'EncryptMetadata' => true,
        ]);
        return [$dict, new self($fileKey, 6, 'AESV3', 'AESV3', true, true)];
    }

    // ---- internals --------------------------------------------------------------------------------

    private function crypt(int $num, int $gen, string $data, string $method, bool $encrypt): string
    {
        if ($method === 'None' || $data === '' && !$encrypt) {
            return $data;
        }
        if ($method === 'AESV3') {
            $key = $this->key;
        } else {
            $k = $this->key . substr(pack('V', $num), 0, 3) . substr(pack('V', $gen), 0, 2) . ($method === 'AESV2' ? 'sAlT' : '');
            $key = substr(md5($k, true), 0, min(strlen($this->key) + 5, 16));
        }
        if ($method === 'RC4') {
            return self::rc4($key, $data);
        }
        $cipher = $method === 'AESV3' ? 'aes-256-cbc' : 'aes-128-cbc';
        if ($encrypt) {
            $iv = random_bytes(16);
            return $iv . (string) openssl_encrypt($data, $cipher, $key, OPENSSL_RAW_DATA, $iv);
        }
        if (strlen($data) < 32 || strlen($data) % 16 !== 0) {
            return strlen($data) === 16 ? '' : $data; // an empty string is just an IV; anything malformed is left as is
        }
        $out = openssl_decrypt(substr($data, 16), $cipher, $key, OPENSSL_RAW_DATA, substr($data, 0, 16));
        return $out === false ? '' : $out;
    }

    /** @return array{0: string, 1: string} stream method, string method */
    private static function methods(Dict $enc, int $v): array
    {
        if ($v < 4) {
            return ['RC4', 'RC4'];
        }
        $cf = $enc->get('CF');
        $pick = static function (?string $name) use ($cf, $v): string {
            if ($name === null || $name === 'Identity') {
                return $name === 'Identity' ? 'None' : ($v >= 5 ? 'AESV3' : 'RC4');
            }
            $f = $cf instanceof Dict ? $cf->get($name) : null;
            $m = $f instanceof Dict ? $f->name('CFM') : null;
            return match ($m) {
                'AESV2' => 'AESV2',
                'AESV3' => 'AESV3',
                'V2' => 'RC4',
                'None' => 'None',
                default => throw new PdfError('pdf.error.encryption_unsupported', ['method' => (string) $m]),
            };
        };
        return [$pick($enc->name('StmF')), $pick($enc->name('StrF'))];
    }

    private static function keyR4(string $pw, string $o, int $p, string $id, int $r, int $len, bool $encMeta, bool $padded = false): string
    {
        $in = ($padded ? substr($pw, 0, 32) : substr(str_pad($pw, 32, self::PAD), 0, 32)) . substr($o, 0, 32) . pack('V', $p & 0xFFFFFFFF) . $id;
        if ($r >= 4 && !$encMeta) {
            $in .= "\xFF\xFF\xFF\xFF";
        }
        $h = md5($in, true);
        if ($r >= 3) {
            for ($i = 0; $i < 50; $i++) {
                $h = md5(substr($h, 0, $len), true);
            }
        }
        return substr($h, 0, $len);
    }

    private static function checkUser(string $key, string $u, string $id, int $r): bool
    {
        if ($r === 2) {
            return hash_equals(substr($u, 0, 32), self::rc4($key, self::PAD));
        }
        $x = self::rc4($key, md5(self::PAD . $id, true));
        for ($i = 1; $i <= 19; $i++) {
            $k = '';
            for ($j = 0, $n = strlen($key); $j < $n; $j++) {
                $k .= chr(ord($key[$j]) ^ $i);
            }
            $x = self::rc4($k, $x);
        }
        return hash_equals(substr($u, 0, 16), substr($x, 0, 16));
    }

    /** Algorithm 2.B (ISO 32000-2) for R6; plain SHA-256 for the deprecated R5. */
    public static function hashR6(string $pw, string $salt, string $udata, int $r = 6): string
    {
        $k = hash('sha256', $pw . $salt . $udata, true);
        if ($r === 5) {
            return $k;
        }
        $round = 0;
        while (true) {
            $k1 = str_repeat($pw . $k . $udata, 64);
            $e = (string) openssl_encrypt($k1, 'aes-128-cbc', substr($k, 0, 16), OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($k, 16, 16));
            $sum = 0;
            for ($i = 0; $i < 16; $i++) {
                $sum += ord($e[$i]);
            }
            $k = hash(['sha256', 'sha384', 'sha512'][$sum % 3], $e, true);
            $round++;
            if ($round >= 64 && ord($e[strlen($e) - 1]) <= $round - 32) {
                break;
            }
        }
        return substr($k, 0, 32);
    }

    public static function rc4(string $key, string $data): string
    {
        $s = range(0, 255);
        $j = 0;
        $kl = strlen($key);
        for ($i = 0; $i < 256; $i++) {
            $j = ($j + $s[$i] + ord($key[$i % $kl])) & 0xFF;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $i = $j = 0;
        $out = '';
        for ($n = 0, $len = strlen($data); $n < $len; $n++) {
            $i = ($i + 1) & 0xFF;
            $j = ($j + $s[$i]) & 0xFF;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
            $out .= chr(ord($data[$n]) ^ $s[($s[$i] + $s[$j]) & 0xFF]);
        }
        return $out;
    }

    private static function bytes(mixed $v): string
    {
        return $v instanceof Str ? $v->bytes : '';
    }

    private static function latin(string $utf8): string
    {
        return (string) mb_convert_encoding($utf8, 'ISO-8859-1', 'UTF-8');
    }

    /** Minimal SASLprep: NFKC when intl is available (passwords are typed text). */
    private static function saslPrep(string $utf8): string
    {
        return class_exists(\Normalizer::class) ? (string) \Normalizer::normalize($utf8, \Normalizer::FORM_KC) : $utf8;
    }
}
