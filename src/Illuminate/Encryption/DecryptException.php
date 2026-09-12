<?php namespace Illuminate\Encryption;

// @deprecated Canonical location is Illuminate\Contracts\Encryption\DecryptException (L13).
// Runtime alias keeps existing catch sites working until the illuminate/encryption swap;
// class_alias makes the two names identical so `catch (Illuminate\Encryption\DecryptException)`
// still catches the thrown contract exception. App repoint is grep-driven (Psalm-blind to alias).
class_alias(\Illuminate\Contracts\Encryption\DecryptException::class, __NAMESPACE__.'\\DecryptException');
