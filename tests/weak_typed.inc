<?php
class Typed {
    public function count(): int { return 1; }
    public function name(): string { return 'n'; }
    public function nothing(): void {}
    public function never(): never { throw new LogicException('never'); }
    public function maybe(): ?int { return null; }
    public function union(): int|string { return 1; }
    public function me(): static { return $this; }
    public function self(): self { return $this; }
    public function untyped() { return 'u'; }
    public function takesInt(int $i): int { return $i; }
    public function takesObj(Typed $t): string { return get_class($t); }
    public function takesUnion(int|string $v): string { return gettype($v); }
    protected function prot(): string { return 'prot'; }
    private function priv(): string { return 'priv'; }
    public function callPriv(): string { return $this->priv(); }
    public function callPrivOn(Typed $o): string { return $o->priv(); }
    public static function stat(): string { return 'stat'; }
    public function __construct(public int $ctorArg = 0) {}
}

