<?php

namespace App\Enums;

enum UserRole: string
{
    case PlatformAdmin = 'platform_admin';
    case CompanyAdmin = 'company_admin';
    case HrStaff = 'hr_staff';
    case DeptManager = 'dept_manager';
    case Reporter = 'reporter';
    case CommitteeMember = 'committee_member';
    case ExternalLegalReviewer = 'external_legal_reviewer';
    case HrPartnerStaff = 'hr_partner_staff';
}
