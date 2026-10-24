<?php

namespace App\Enums;

/**
 * The positions defined by the JDW contract. A person holds at most one at a
 * time; holding none is shown as OUTSIDE_LABEL.
 */
enum ContractPosition: string
{
    public const OUTSIDE_LABEL = 'Outside contracted team';

    case CeoCto = 'ceo_cto';
    case HeadOfDelivery = 'head_of_delivery';
    case HeadOfDevelopment = 'head_of_development';
    case HeadOfSupport = 'head_of_support';
    case PlannerProductOwner = 'planner_product_owner';
    case TechnicalArchitect = 'technical_architect';
    case SeniorDeveloper = 'senior_developer';
    case UxDesign = 'ux_design';
    case Developer = 'developer';
    case ProjectManager = 'project_manager';
    case Tester = 'tester';
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::CeoCto => 'CEO and CTO (50% each)',
            self::HeadOfDelivery => 'Head of Delivery',
            self::HeadOfDevelopment => 'Head of Development',
            self::HeadOfSupport => 'Head of Support',
            self::PlannerProductOwner => 'Planner / Product Owner',
            self::TechnicalArchitect => 'Technical Architect',
            self::SeniorDeveloper => 'Senior Developer',
            self::UxDesign => 'UX and Design',
            self::Developer => 'Developer',
            self::ProjectManager => 'Project Manager',
            self::Tester => 'Tester',
            self::Support => 'Support',
        };
    }
}
