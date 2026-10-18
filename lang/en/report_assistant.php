<?php

return [
    'opening' => 'Describe what happened in your own words. I will ask short questions about missing details.',
    'yes' => 'Yes', 'someone_else' => 'Someone else', 'not_in_list' => 'Not in list',
    'confirm_match' => 'Do you mean :name · :code?',
    'chip' => ['unknown' => 'I do not know', 'today' => 'Today', 'yesterday' => 'Yesterday', 'none' => 'None'],
    'summary_prefix' => 'According to the reporter: ',
    'question' => [
        'who' => 'Who was involved?', 'what' => 'What happened?',
        'where' => 'Where did it happen?', 'when' => 'When did it happen? An approximate time is fine.',
        'witnesses' => 'Were there any witnesses?',
    ],
    'progress' => ['who' => 'Who', 'what' => 'What', 'where' => 'Where', 'when' => 'When', 'witnesses' => 'Witnesses'],
    // Review labels follow the report's language, not the interface language, so the report reads in one direction.
    'review' => [
        'summary' => 'Summary', 'summary_ai' => 'Written by the assistant: check it',
        'people' => 'People', 'employees' => 'Employees concerned', 'employees_unknown' => 'Not known',
        'witnesses' => 'Witnesses', 'witnesses_none' => 'No witnesses', 'witnesses_unknown' => 'Not known if anyone saw it',
        'responsible' => 'Named as responsible',
        'place_time' => 'Place and time', 'place' => 'Place', 'date' => 'Date', 'time_unknown' => 'time not given',
        'unknown' => 'Not known',
        'what' => 'What happened', 'type' => 'Violation type', 'description' => 'Description', 'how' => 'How it started',
        'injuries' => 'Injuries and damage', 'attachments' => 'Attachments', 'no_attachments' => 'No attachments',
    ],
];
