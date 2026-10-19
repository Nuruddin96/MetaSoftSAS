<?php

namespace App\Support\Platform;

use Illuminate\Support\Str;

/**
 * The 50 business categories offered when a brand registers, in display
 * order. The brand_categories table is the runtime source of truth
 * (registration, owner/admin forms, profiles, directory filters, awards);
 * database/sql/chunk68.sql loads exactly this list into it — see
 * BusinessCategoriesTest, which keeps the two in step. Slugs are
 * Str::slug(name), so "Food & Beverage" and "Education & Training" match the
 * rows chunk64.sql already created.
 */
class BusinessCategories
{
    public const NAMES = [
        'Clothing & Fashion',
        'Information Technology & Software',
        'Digital Marketing & Advertising',
        'Food & Beverage',
        'Agriculture & Agribusiness',
        'Real Estate & Property Development',
        'Construction & Engineering',
        'Healthcare & Medical Services',
        'Pharmaceuticals & Healthcare Products',
        'Education & Training',
        'Beauty & Personal Care',
        'Manufacturing & Industrial Products',
        'Import & Export',
        'Wholesale & Retail Trade',
        'Transportation & Logistics',
        'Banking, Finance & Insurance',
        'Travel & Tourism',
        'Hospitality & Accommodation',
        'Media & Entertainment',
        'Professional & Business Services',
        'Electronics & Technology Products',
        'Telecommunications & Networking',
        'Automotive & Vehicle Services',
        'Furniture & Home Decor',
        'Interior Design & Architecture',
        'Electrical & Electronics Services',
        'Energy & Renewable Energy',
        'Security & Surveillance Services',
        'Printing, Packaging & Publishing',
        'Human Resources & Recruitment',
        'Event Management & Wedding Services',
        'Business Consulting & Advisory',
        'NGOs & Social Enterprises',
        'Fashion Accessories & Lifestyle Products',
        'Sports & Fitness',
        'Beauty, Wellness & Spa Services',
        'Legal Services',
        'Accounting, Tax & Audit Services',
        'Cleaning & Facility Management',
        'Courier & Delivery Services',
        'Marine & Shipping Services',
        'Garments & Textile Industry',
        'Leather & Footwear',
        'Plastic, Rubber & Chemical Products',
        'Water, Sanitation & Environmental Services',
        'Research & Development',
        'Franchise & Business Opportunities',
        'Repair & Maintenance Services',
        'Pet Care & Animal Services',
        'Religious & Cultural Services',
    ];

    /** @return array<string, string> slug => name, in display order */
    public static function bySlug(): array
    {
        return collect(self::NAMES)->mapWithKeys(fn ($name) => [Str::slug($name) => $name])->all();
    }
}
