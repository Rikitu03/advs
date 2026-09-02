@include('errors.layout', [
    'statusCode' => 403,
    'heading' => 'Access Denied',
    'message' => 'You do not have permission to access this page.',
    'details' => 'Your current ADVS role cannot open this resource.',
    'nextStep' => 'Return to the homepage or ask an administrator to review your account permissions.',
    'errorKey' => 'ACCESS_DENIED',
    'icon' => 'lock-closed',
])
