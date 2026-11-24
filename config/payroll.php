<?php

return [
    'engine_version' => '9.0.0',
    // End of verification coverage, not a claimed legal expiry. Later payroll is gated.
    'verified_through' => '2026-11-22',
    'sources' => [
        'sss' => ['version' => 'SSS 2024-006', 'effective_from' => '2025-01-01', 'effective_to' => null, 'status' => 'verified_standard_employed', 'url' => 'https://www.sss.gov.ph/wp-content/uploads/2024/12/Cir-2024-006-Employers-scaled.jpg'],
        'philhealth' => ['version' => 'PA2025-0002 / PA2026-0042', 'effective_from' => '2025-01-01', 'effective_to' => null, 'status' => 'verified_standard_direct', 'url' => 'https://www.philhealth.gov.ph/advisories/2025/PA2025-0002.pdf', 'confirmation_url' => 'https://www.philhealth.gov.ph/advisories/2026/PA2026-0042.pdf'],
        'pagibig' => ['version' => 'Circular 460 (content verification pending)', 'effective_from' => '2024-02-01', 'effective_to' => null, 'status' => 'needs_verification', 'url' => 'https://www.pagibigfund.gov.ph/about_publication_circulars_memberservices.html'],
        'bir' => ['version' => 'RR11-2018 Annex E 2023+', 'effective_from' => '2023-01-01', 'effective_to' => null, 'status' => 'verified_regular_compensation_table', 'url' => 'https://bir-cdn.bir.gov.ph/local/pdf/Annex%20E%20RR%2011-2018.pdf'],
        'labor' => ['version' => 'DOLE Handbook 2024; classification/calendar approval required', 'effective_from' => '2024-01-01', 'effective_to' => null, 'status' => 'needs_employee_coverage_review', 'url' => 'https://nwpc.dole.gov.ph/wp-content/uploads/2024/11/Workers-Statutory-Monetary-Benefits-Handbook-2024-Edition.pdf'],
    ],
];
