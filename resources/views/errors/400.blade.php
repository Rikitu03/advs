@include('errors.layout', [
    'statusCode' => 400,
    'heading' => 'Bad Request',
    'message' => 'The request could not be processed. Please check your information and try again.',
    'details' => 'The information sent to ADVS was incomplete or could not be understood.',
    'nextStep' => 'Review the information you entered, then return to a safe page and submit it again.',
    'errorKey' => 'BAD_REQUEST',
    'icon' => 'exclamation-triangle',
])
