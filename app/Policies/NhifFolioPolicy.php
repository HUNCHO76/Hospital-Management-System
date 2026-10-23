<?php

namespace App\Policies;

/**
 * Backward compatibility alias for NhifClaimPolicy.
 * Retained so existing callers/tests referencing NhifFolioPolicy continue without regression.
 */
class NhifFolioPolicy extends NhifClaimPolicy {}
