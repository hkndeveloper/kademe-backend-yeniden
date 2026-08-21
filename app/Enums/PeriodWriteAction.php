<?php

namespace App\Enums;

enum PeriodWriteAction: string
{
    case CONFIGURE_PERIOD = 'configure_period';
    case CREATE_OPERATION = 'create_operation';
    case RESOLVE_OPERATION = 'resolve_operation';
    case ARCHIVE_CORRECTION = 'archive_correction';
}
