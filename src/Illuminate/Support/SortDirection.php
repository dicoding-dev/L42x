<?php

namespace Illuminate\Support;

// ponytail: minimal reconstruction — laravel/framework v13 references SortDirection
// from Collection::sort*() but ships no defining file; only ::Ascending/::Descending
// are ever used (never ->value/::from), so a pure enum is the whole contract.
enum SortDirection
{
    case Ascending;
    case Descending;
}
