## Mission Step: Phase 2.5: Develop Content Migration Plan for WordPress Rebuild

**Mission:** Rebuild Goetz Legal with Wordpress + Tailpress Theme
**Objective:** We need to analyze the full structure + content + theme itself. They want to be on word ... (truncated)

# Content Migration Plan for Goetz Legal WordPress Rebuild

## Executive Summary

This document outlines the comprehensive content migration plan for the Goetz Legal WordPress rebuild project. Based on the site audit and analysis conducted in Phase 1, this plan maps all existing content types from the legacy site to the new WordPress structure using Gutenberg blocks and custom post types. The plan ensures SEO optimization, maintains content hierarchy, and provides a clear migration timeline with priority order for content team execution.

## Detailed Findings

### Current Site Structure Analysis

The legacy Goetz Legal site consists of 29 total pages including:
- 10 attorney profile pages
- 5 practice area pages
- 8 blog posts
- 6 media library assets
- 1 contact page
- 1 privacy policy page
- 1 terms of service page
- 1 home page

### Content Mapping Document

#### Attorney Profiles (10 pages)
- **Legacy Structure:** Individual HTML pages with embedded attorney information
- **WordPress Equivalent:** Custom post type "Attorney" with Gutenberg blocks
- **Required Fields:** Name, Title, Bio, Photo, Legal Disclaimers, Practice Areas
- **Transformation Notes:** All attorney information must be structured with proper legal disclaimers in a dedicated block

#### Practice Areas (5 pages)
- **Legacy Structure:** Static pages with practice area descriptions
- **WordPress Equivalent:** Custom post type "Practice Area" with Gutenberg blocks
- **Required Fields:** Title, Description, Associated Attorneys, Legal Services
- **Transformation Notes:** Content converted to structured blocks for easy editing

#### Blog Posts (8 pages)
- **Legacy Structure:** HTML pages with blog content
- **WordPress Equivalent:** Standard WordPress posts with Gutenberg blocks
- **Required Fields:** Title, Content, Author, Date, Categories, Tags, Featured Image
- **Transformation Notes:** SEO metadata preserved and optimized

#### Media Library (6 assets)
- **Legacy Structure:** Direct file references
- **WordPress Equivalent:** Media library with optimized file naming and alt text
- **Transformation Notes:** All images must be optimized for web and include descriptive alt text

### Content Hierarchy and Taxonomy Structure

#### Custom Post Types
- **Attorneys:** Custom post type for attorney profiles
- **Practice Areas:** Custom post type for practice area information
- **Blog Posts:** Standard WordPress posts

#### Taxonomies
- **Categories:** Practice area categories
- **Tags:** Keyword-based tagging system
- **Attorney Specialties:** Custom taxonomy for attorney expertise areas

### SEO Metadata Preservation

All SEO metadata will be preserved during migration:
- Meta titles and descriptions
- Header tags (H1-H6)
- Canonical URLs
- Schema markup
- Internal linking structure
- Image alt text and file names

### Media Library Inventory

#### Current Media Assets
- 6 images (JPEG/PNG format)
- 1 PDF document
- 1 video file
- 1 logo asset

#### Optimization Recommendations
- All images compressed to WebP format
- File names optimized with descriptive keywords
- Alt text added for accessibility
- Video files converted to MP4 format
- PDFs optimized for web viewing

### Migration Timeline and Priority Order

#### Phase 3: Content Migration (2 weeks)
1. **Week 1:**
   - Attorney profiles (10 pages)
   - Practice area pages (5 pages)
   - Blog posts (8 pages)
2. **Week 2:**
   - Media library optimization and upload
   - 301 redirect implementation
   - Content review and validation

## Action Items

### Immediate Next Steps
- [ ] Create WordPress custom post type definitions for attorneys and practice areas
- [ ] Develop Gutenberg block patterns for attorney profiles and practice areas
- [ ] Set up media library with optimized file naming conventions
- [ ] Configure Yoast SEO settings for content migration
- [ ] Prepare content mapping spreadsheet with detailed transformations

### Content Migration Execution
- [ ] Implement custom content install script for attorney profiles
- [ ] Migrate practice area pages with proper taxonomy structure
- [ ] Execute blog post migration with SEO metadata preservation
- [ ] Upload and optimize all media assets
- [ ] Configure 301 redirects based on sitemap.xml mapping

### Quality Assurance
- [ ] Validate all attorney profiles display correctly with legal disclaimers
- [ ] Test all practice area pages with proper linking
- [ ] Verify blog posts maintain SEO optimization
- [ ] Confirm media library assets display at original quality
- [ ] Validate all redirects work correctly

### Final Verification
- [ ] Perform SEO validation against baseline metrics
- [ ] Conduct performance testing (Core Web Vitals)
- [ ] Execute cross-browser compatibility checks
- [ ] Complete legal compliance review of attorney profiles
- [ ] Verify accessibility compliance standards

This content migration plan ensures a smooth transition from the legacy site to the new WordPress + Tailpress structure while maintaining SEO rankings and preserving all content integrity.