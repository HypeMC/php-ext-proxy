--TEST--
Dynamic class-name handling preserves native compile-time rejection of constant-folded scalar operands
--EXTENSIONS--
proxy
--FILE--
<?php
echo "must not execute\n";
(1 + 1)::class;
?>
--EXPECTREGEX--
Fatal error: Cannot use "::class" on int in .+ on line 3(?:\nStack trace:\n#0 \{main\})?
