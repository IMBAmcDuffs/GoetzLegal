<?php
/**
 * REST-first importer for the Goetz Legal rebuild.
 *
 * @package GoetzMigration
 */

if (!defined('ABSPATH')) {
    exit;
}

class Goetz_Migration_Scraper
{
    /**
     * The only public pages approved for the v1 rebuild.
     *
     * @var array<string, array{slug: string, title: string, rest_slug: string}>
     */
    private array $approved_pages = [
        '/'                  => ['slug' => 'home', 'title' => 'Home', 'rest_slug' => 'home'],
        '/james-l-goetz/'    => ['slug' => 'james-l-goetz', 'title' => 'James L. Goetz', 'rest_slug' => 'james-l-goetz'],
        '/gregory-w-goetz/'  => ['slug' => 'gregory-w-goetz', 'title' => 'Gregory W. Goetz', 'rest_slug' => 'gregory-w-goetz'],
        '/staff/'            => ['slug' => 'staff', 'title' => 'Staff', 'rest_slug' => 'staff'],
        '/questions/'        => ['slug' => 'questions', 'title' => 'Questions', 'rest_slug' => 'questions'],
        '/links/'            => ['slug' => 'links', 'title' => 'Links', 'rest_slug' => 'links'],
        '/contact/'          => ['slug' => 'contact', 'title' => 'Contact', 'rest_slug' => 'contact'],
    ];

    /**
     * Discover and fetch source pages without writing posts.
     *
     * @param string $source_url Source site URL.
     * @return array<int, array<string, mixed>>
     */
    public function scan_site(string $source_url = 'https://goetzlegal.com'): array
    {
        $source_url = untrailingslashit($source_url);
        $sitemap_paths = $this->discover_sitemap_paths($source_url);
        $pages = [];

        foreach ($this->approved_pages as $path => $meta) {
            if (!in_array($path, $sitemap_paths, true) && $path !== '/') {
                continue;
            }

            $page = $this->fetch_rest_page($source_url, $meta['rest_slug']);

            if (!$page) {
                $page = $this->fetch_html_page($source_url . $path, $meta);
            }

            if (!$page) {
                $page = [
                    'title'          => $meta['title'],
                    'slug'           => $meta['slug'],
                    'path'           => $path,
                    'url'            => $source_url . $path,
                    'raw_content'    => '',
                    'method'         => 'fallback',
                    'modified'       => '',
                    'yoast'          => [],
                    'media_count'    => 0,
                    'source_hash'    => md5($meta['title'] . $path),
                ];
            }

            $page['title'] = $page['title'] ?: $meta['title'];
            $page['slug'] = $meta['slug'];
            $page['path'] = $path;
            $page['source_hash'] = md5(($page['title'] ?? '') . ($page['raw_content'] ?? '') . ($page['modified'] ?? ''));
            $page['media_count'] = $this->count_media_references((string) ($page['raw_content'] ?? ''));
            $pages[] = $page;
        }

        update_option('goetz_migration_scan_data', $pages, false);

        return $pages;
    }

    /**
     * Import source pages into WordPress as normal pages.
     *
     * @param string $source_url Source site URL.
     * @return array{created: int, updated: int, skipped: int}
     */
    public function import_site(string $source_url = 'https://goetzlegal.com'): array
    {
        $pages = $this->scan_site($source_url);
        $summary = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $imported_ids = [];

        foreach ($pages as $page) {
            $existing_id = $this->find_existing_page((string) $page['slug'], (string) $page['url']);
            $same_hash = $existing_id && get_post_meta($existing_id, '_goetz_source_hash', true) === $page['source_hash'];
            $same_content_version = $existing_id && get_post_meta($existing_id, '_goetz_content_version', true) === GOETZ_MIGRATION_CONTENT_VERSION;
            $existing_content = $existing_id ? (string) get_post_field('post_content', $existing_id) : '';
            $has_remote_media = $existing_id && strpos($existing_content, 'goetzlegal.com/wp-content/uploads') !== false;
            $needs_form_refresh = $existing_id
                && ($page['slug'] ?? '') === 'contact'
                && post_type_exists('wpforms')
                && (strpos($existing_content, '[wpforms id="1"') !== false || $this->contact_form_needs_refresh());

            if ($same_hash && $same_content_version && !$has_remote_media && !$needs_form_refresh) {
                $summary['skipped']++;
                $imported_ids[(string) $page['slug']] = $existing_id;
                continue;
            }

            $postarr = [
                'post_title'     => (string) $page['title'],
                'post_name'      => (string) $page['slug'],
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ];

            if ($existing_id) {
                $postarr['ID'] = $existing_id;
                $post_id = wp_update_post($postarr, true);
                $summary['updated']++;
            } else {
                $post_id = wp_insert_post($postarr, true);
                $summary['created']++;
            }

            if (is_wp_error($post_id)) {
                continue;
            }

            $content = $this->build_page_content((string) $page['slug'], $page, (int) $post_id);
            $content = $this->localize_remote_media($content, (int) $post_id);

            wp_update_post([
                'ID'           => (int) $post_id,
                'post_content' => $content,
            ]);

            update_post_meta((int) $post_id, '_goetz_source_url', esc_url_raw((string) $page['url']));
            update_post_meta((int) $post_id, '_goetz_source_hash', (string) $page['source_hash']);
            update_post_meta((int) $post_id, '_goetz_content_version', GOETZ_MIGRATION_CONTENT_VERSION);
            update_post_meta((int) $post_id, '_goetz_imported_at', current_time('mysql'));
            $this->import_yoast_meta((int) $post_id, $page);

            $imported_ids[(string) $page['slug']] = (int) $post_id;
        }

        $this->configure_site($imported_ids);

        return $summary;
    }

    /**
     * Backward-compatible alias for the previous plugin UI.
     *
     * @param string $base_url Source URL.
     * @return array<int, array<string, mixed>>
     */
    public function scrape_site(string $base_url): array
    {
        return $this->scan_site($base_url);
    }

    /**
     * Backward-compatible alias for the previous plugin UI.
     */
    public function generate_posts(): int
    {
        $summary = $this->import_site('https://goetzlegal.com');
        return $summary['created'] + $summary['updated'];
    }

    /**
     * @return array<int, string>
     */
    private function discover_sitemap_paths(string $source_url): array
    {
        $response = $this->remote_get($source_url . '/page-sitemap.xml', ['timeout' => 20]);
        $body = is_wp_error($response) ? '' : wp_remote_retrieve_body($response);

        if (!$body) {
            return array_keys($this->approved_pages);
        }

        preg_match_all('/<loc>(.*?)<\/loc>/i', $body, $matches);
        $paths = [];

        foreach ($matches[1] ?? [] as $loc) {
            $path = wp_parse_url(html_entity_decode($loc), PHP_URL_PATH) ?: '/';
            $path = trailingslashit($path);
            if ($path === '//') {
                $path = '/';
            }
            if (isset($this->approved_pages[$path])) {
                $paths[] = $path;
            }
        }

        return $paths ?: array_keys($this->approved_pages);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetch_rest_page(string $source_url, string $slug): ?array
    {
        $endpoint = add_query_arg(
            [
                'slug'    => $slug,
                '_fields' => 'slug,link,title,content,yoast_head_json,modified,date',
            ],
            $source_url . '/wp-json/wp/v2/pages'
        );
        $response = $this->remote_get($endpoint, ['timeout' => 25]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $items = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($items) || empty($items[0])) {
            return null;
        }

        $item = $items[0];

        return [
            'title'       => wp_strip_all_tags($item['title']['rendered'] ?? ''),
            'slug'        => $slug,
            'url'         => $item['link'] ?? '',
            'raw_content' => $item['content']['rendered'] ?? '',
            'method'      => 'rest',
            'modified'    => $item['modified'] ?? '',
            'yoast'       => $item['yoast_head_json'] ?? [],
        ];
    }

    /**
     * @param array{slug: string, title: string, rest_slug: string} $meta
     * @return array<string, mixed>|null
     */
    private function fetch_html_page(string $url, array $meta): ?array
    {
        $response = $this->remote_get($url, ['timeout' => 25, 'user-agent' => 'GoetzMigration/2.0']);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $html = wp_remote_retrieve_body($response);
        if (!$html) {
            return null;
        }

        preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $title_match);
        preg_match('/<main[^>]*>(.*?)<\/main>/is', $html, $content_match);

        return [
            'title'       => isset($title_match[1]) ? trim(wp_strip_all_tags($title_match[1])) : $meta['title'],
            'slug'        => $meta['slug'],
            'url'         => $url,
            'raw_content' => $content_match[1] ?? $html,
            'method'      => 'html',
            'modified'    => '',
            'yoast'       => [],
        ];
    }

    private function find_existing_page(string $slug, string $source_url): int
    {
        $matches = get_posts([
            'post_type'      => 'page',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_goetz_source_url',
            'meta_value'     => $source_url,
        ]);

        if (!empty($matches[0])) {
            return (int) $matches[0];
        }

        $page = get_page_by_path($slug, OBJECT, 'page');

        return $page ? (int) $page->ID : 0;
    }

    /**
     * Fetch directly first, then use an optional proxy endpoint only when needed.
     *
     * The proxy can be a URL containing `{url}` or a URL that accepts a `url`
     * query parameter.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    private function remote_get(string $url, array $args)
    {
        $response = wp_remote_get($url, $args);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            return $response;
        }

        $proxy_url = $this->fetch_proxy_url();
        if (!$proxy_url) {
            return $response;
        }

        if (strpos($proxy_url, '{url}') !== false) {
            $proxy_url = str_replace('{url}', rawurlencode($url), $proxy_url);
        } else {
            $proxy_url = add_query_arg('url', $url, $proxy_url);
        }

        return wp_remote_get($proxy_url, $args);
    }

    private function fetch_proxy_url(): string
    {
        $proxy_url = (string) get_option('goetz_migration_fetch_proxy_url', '');

        if (!$proxy_url) {
            $proxy_url = (string) getenv('GOETZ_MIGRATION_FETCH_PROXY_URL');
        }

        return esc_url_raw($proxy_url);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function build_page_content(string $slug, array $page, int $post_id): string
    {
        switch ($slug) {
            case 'home':
                return $this->home_content($post_id);
            case 'james-l-goetz':
                return $this->bio_content('james', $post_id);
            case 'gregory-w-goetz':
                return $this->bio_content('gregory', $post_id);
            case 'staff':
                return $this->staff_content();
            case 'questions':
                return $this->questions_content();
            case 'links':
                return $this->links_content($page);
            case 'contact':
                return $this->contact_content();
            default:
                return wp_kses_post((string) ($page['raw_content'] ?? ''));
        }
    }

    private function home_content(int $post_id): string
    {
        $hero_image = 'https://goetzlegal.com/wp-content/uploads/2025/03/Goetz-Legal-Exterior-1.png';
        $intro_left = 'https://goetzlegal.com/wp-content/uploads/2022/08/PXL_20220818_164549897_2.jpg';
        $intro_right = 'https://goetzlegal.com/wp-content/uploads/2024/01/Sue.jpg';
        $scale_icon = 'https://goetzlegal.com/wp-content/uploads/2022/08/law-scale-icon-purple.png';
        $firm_bg = 'https://goetzlegal.com/wp-content/uploads/2022/08/firm-bg.jpg';
        $james_image = 'https://goetzlegal.com/wp-content/uploads/2022/08/JAMES-L.jpg';
        $gregory_image = 'https://goetzlegal.com/wp-content/uploads/2025/03/Greg-Website-Portrait-6.jpg';

        return $this->block('goetz/hero', [
            'eyebrow'    => 'GoetzLegal.com',
            'heading'    => 'A law firm with<br>seasoned trial<br>attorneys <b>in</b><br><b>Fort Myers,<br>Florida.</b>',
            'content'    => 'Goetz & Goetz represents all individuals who need legal advice in regards to corporate, construction, real estate, probate, criminal and bankruptcy matters. Goetz & Goetz has been a legal resource in Fort Myers for over 50 years and has a vast amount of legal experience at your disposal.',
            'imageUrl'   => $hero_image,
            'imageAlt'   => 'Goetz Legal exterior',
            'buttonText' => 'Learn More About Us',
            'buttonUrl'  => home_url('/james-l-goetz/'),
        ])
        . $this->html_block(
            '<section class="goetz-intro-section">'
            . '<div class="goetz-intro">'
            . '<img class="goetz-intro__image" src="' . esc_url($intro_left) . '" alt="James L. Goetz recognition plaque" loading="lazy">'
            . '<div class="goetz-intro__content">'
            . '<h2><strong>Mr. Goetz welcomes</strong> you to browse this site to learn more about his firm and get information.</h2>'
            . '<img class="goetz-intro__icon" src="' . esc_url($scale_icon) . '" alt="" loading="lazy">'
            . '<p>If you would like to speak with Mr. Goetz, please call <strong>' . esc_html(GOETZ_LEGAL_PHONE_DISPLAY) . '</strong> or contact the firm <a href="' . esc_url(home_url('/contact/')) . '">online</a>.</p>'
            . '</div>'
            . '<img class="goetz-intro__image" src="' . esc_url($intro_right) . '" alt="Goetz Legal office library photo" loading="lazy">'
            . '</div>'
            . '</section>'
        )
        . $this->html_block(
            '<section class="goetz-practice-band">'
            . '<div class="goetz-practice-band__image"><img src="' . esc_url($firm_bg) . '" alt="Law office books and desk" loading="lazy"></div>'
            . '<div class="goetz-practice-band__content">'
            . '<h2>Providing <strong>Legal Advice</strong> in:</h2>'
            . '<ul class="goetz-practice-list">'
            . '<li><span aria-hidden="true"><img src="' . esc_url($scale_icon) . '" alt="" loading="lazy"></span><b>Corporate</b></li>'
            . '<li><span aria-hidden="true"><img src="' . esc_url($scale_icon) . '" alt="" loading="lazy"></span><b>Construction</b></li>'
            . '<li><span aria-hidden="true"><img src="' . esc_url($scale_icon) . '" alt="" loading="lazy"></span><b>Real Estate</b></li>'
            . '<li><span aria-hidden="true"><img src="' . esc_url($scale_icon) . '" alt="" loading="lazy"></span><b>Probate</b></li>'
            . '<li><span aria-hidden="true"><img src="' . esc_url($scale_icon) . '" alt="" loading="lazy"></span><b>Criminal</b></li>'
            . '<li><span aria-hidden="true"><img src="' . esc_url($scale_icon) . '" alt="" loading="lazy"></span><b>Bankruptcy</b></li>'
            . '<li><span aria-hidden="true"><img src="' . esc_url($scale_icon) . '" alt="" loading="lazy"></span><b>Appeals</b></li>'
            . '</ul>'
            . '</div>'
            . '</section>'
        )
        . $this->section(
            $this->heading('Attorneys')
            . '<div class="goetz-card-grid">'
            . $this->block('goetz/attorney-card', [
                'name'       => 'James L. Goetz',
                'bio'        => 'James L. Goetz was born in Erie, Pennsylvania. He grew up in Oil City and Girard, Pennsylvania working on his father\'s farm and coal mines until he went to college.',
                'imageUrl'   => $james_image,
                'imageAlt'   => 'James L. Goetz',
                'profileUrl' => home_url('/james-l-goetz/'),
            ])
            . $this->block('goetz/attorney-card', [
                'name'       => 'Gregory W. Goetz',
                'bio'        => 'Mr. Gregory W. Goetz was born and raised here in Fort Myers, Florida. He attended Fort Myers High School and then was accepted to University of Florida.',
                'imageUrl'   => $gregory_image,
                'imageAlt'   => 'Gregory W. Goetz',
                'profileUrl' => home_url('/gregory-w-goetz/'),
            ])
            . '</div>',
            'goetz-section goetz-section--attorneys'
        )
        . $this->block('goetz/cta');
    }

    private function bio_content(string $which, int $post_id): string
    {
        if ($which === 'james') {
            $image = 'https://goetzlegal.com/wp-content/uploads/2022/08/JAMES-L.jpg';
            $body = 'James L. Goetz was born in Erie, Pennsylvania. He grew up in Oil City and Girard, Pennsylvania working on his father\'s farm and coal mines until he went to college. Mr. Goetz received his B.A. in political science and a minor in economics from the University of Pittsburgh in 1969. He earned his Juris Doctorate from University of Akron in 1972. From 1972 to 1975, Mr. Goetz served as a Captain in the Judge Advocate General Corps of the United States Army. Mr. Goetz later moved to Fort Myers to begin practicing law at Roberts and Watson, where he later became a partner. Mr. Goetz has been practicing law in Fort Myers for more than 50 years. Mr. Goetz\'s Practice Areas include: Estates, Real Estate, Trial, Probate, Construction Law, Bankruptcy, and Commercial Litigation. Mr. Goetz was admitted to the Ohio Bar, Florida Bar, and U.S. Court of Military Appeals in 1972, U.S. Supreme Court in 1976, and also admitted to practice in the United States District Court, Middle District of Florida. Mr. Goetz is a member of the Florida and Ohio State Bar and is also a member of the Lee County Bar association.';
            $name = 'James L. Goetz';
            $email = 'info@goetzlegal.com';
        } else {
            $image = 'https://goetzlegal.com/wp-content/uploads/2025/03/Greg-Website-Portrait-6.jpg';
            $body = 'Mr. Gregory W. Goetz was born and raised here in Fort Myers, Florida. He attended Fort Myers High School and then was accepted to University of Florida. Mr. Goetz graduated with honors with a degree in history. Mr. Goetz spent time at other Universities while on break from University of Florida. He took extended classes in history at Boston University, economics and history at University of Cambridge, U.K., and criminology at Florida Gulf Coast University, so that he would receive a more diverse education. After graduating from University of Florida, Mr. Goetz worked in Fort Myers for a few years before going to law school at Nova Southeastern University. While at the college of Law, Mr. Goetz worked at the Broward County State Attorney\'s Office, Homicide Unit. Mr. Goetz sat second chair on numerous high profile murder cases and helped the prosecutors with their arguments and motions. Mr. Goetz successfully argued his way on Moot Court, received a book award and countless other top grades while in law school. When Mr. Goetz graduated from law school he began working with the 20th Judicial Public Defenders\' Office where he began representing juveniles with misdemeanor and felony charges. Mr. Goetz was promoted to a felony division, where he did numerous jury trials as lead attorney, from jury selection to verdict. Mr. Goetz also appeared in court on behalf of clients for arraignments, motions, violations of probation, civil injunctions, and pleas. After Mr. Goetz\'s tenure at the Public Defender\'s Office was over, he went to work at James L. Goetz P.A. While being employed at Goetz & Goetz, Mr. Goetz has done numerous hearings, motions, and appeals to the 2nd D.C.A. Mr. Goetz has extensive legal knowledge and is more than willing to hear your issues and resolve those issues to the best of his ability. Mr. Goetz is licensed to practice law in all Florida State Courts, District of Columbia, and the following Federal Courts: United States Supreme Court, United States Court of Appeals for the Eleventh Circuit, United States Middle District of Florida, and United States Southern District of Florida. Please do not hesitate to contact Goetz & Goetz, to settle your legal issues.';
            $name = 'Gregory W. Goetz';
            $email = 'goetzg@goetzlegal.com';
        }

        return $this->section(
            $this->block('goetz/attorney-card', [
                'name'     => $name,
                'email'    => $email,
                'imageUrl' => $image,
                'imageAlt' => $name,
            ])
            . $this->paragraph($body)
            . $this->paragraph('Email ' . $name)
        )
        . $this->block('goetz/cta');
    }

    private function staff_content(): string
    {
        $scale_icon = 'https://goetzlegal.com/wp-content/uploads/2022/08/law-scale-icon-purple.png';

        return $this->html_block(
            '<section class="goetz-staff-section">'
            . '<div class="goetz-staff-section__inner">'
            . '<header class="goetz-staff-heading">'
            . '<h2><strong>Legal</strong> Staff</h2>'
            . '<img src="' . esc_url($scale_icon) . '" alt="" width="40" height="39" loading="lazy">'
            . '</header>'
            . '<div class="goetz-staff-grid">'
            . $this->staff_card('Gregory W. Goetz, Esq.', 'Partner', 'goetzg@goetzlegal.com')
            . $this->staff_card('Dawn', 'Office Manager', 'info@goetzlegal.com')
            . '</div>'
            . '</div>'
            . '</section>'
        )
        . $this->block('goetz/cta');
    }

    private function staff_card(string $name, string $role, string $email): string
    {
        return '<article class="goetz-staff-card">'
            . '<span class="goetz-staff-card__icon" aria-hidden="true">'
            . '<svg viewBox="0 0 24 24" focusable="false"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4Zm0 2c-3.31 0-7 1.67-7 4v1h14v-1c0-2.33-3.69-4-7-4Z"/></svg>'
            . '</span>'
            . '<h3>' . esc_html($name) . '</h3>'
            . '<p class="goetz-staff-card__role">' . esc_html($role) . '</p>'
            . '<p><a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a></p>'
            . '</article>';
    }

    private function questions_content(): string
    {
        return $this->section(
            $this->block('goetz/faq-list', [
                'items' => [
                    [
                        'question' => 'What should I know about building a home in Florida?',
                        'answer'   => 'It is extremely important to retain the services of a properly licensed, experienced and financially sound construction company. This can be accomplished by contacting various trade organizations or by speaking with an attorney experienced in construction matters. The State of Florida has a Construction Lien Law to which the homebuilder must strictly adhere in order to avoid the possibility of having to make duplicate payments for construction. Federal Copyright Laws also govern design and construction of homes and have severe financial penalties for using all or part of another person\'s or corporation\'s protected design. The best time to address all of these concerns is before you sign a construction contract with a builder, and the best way to do so is by obtaining a thorough evaluation of the contract from an attorney.',
                    ],
                    [
                        'question' => 'To what extent are homesteads protected from claims of creditors in Florida?',
                        'answer'   => 'While a person\'s homestead, defined as a maximum of one-half acre inside a municipality or 160 acres outside a municipality, is obviously not protected if the owner has pledged it as collateral by, for example, a mortgage, the Florida Constitution does provide an unlimited protection from the claims of non-consensual creditors for legal residents of Florida. The property must meet certain definitions to qualify as homestead property, and the timing of the creation of the homestead in relation to creditors\' claims may be an issue in evaluating the legitimacy of the homestead exemption from execution and forced sale.',
                    ],
                    [
                        'question' => 'Are handwritten wills valid in Florida?',
                        'answer'   => 'Florida does not recognize holographic (handwritten) wills unless they meet the requirements for all valid wills, which is that the testator sign the will in the presence of two witnesses who then sign the will as witnesses in the presence of the testator and each other. Ideally, in addition, wills should be self-proven with appropriate language and notarization in order that the witnesses will not have to be located after the testator\'s death.',
                    ],
                    [
                        'question' => 'I am buying a home in Florida; why do I need a lawyer?',
                        'answer'   => 'A lawyer is uniquely qualified to advise you in all legal aspects of a home purchase, and should be consulted when you start shopping for your home rather than after you sign the purchase contract. Unlike the real estate broker, who often owes his loyalty to the seller for whom he is the agent, your lawyer is working only for you. Your lawyer is an invaluable source of information about area regulations, such as your ability to rebuild your home should it be damaged by a storm. He or she typically knows about planned taxing districts, which could affect the future cost of maintaining your home, as well as other government projects. Your lawyer is best suited to protect your interests once you have selected the property you wish to purchase. The lawyer will make sure the purchase contract is enforceable, has customary provisions, and protects you. He or she can provide for the type of ownership of the home that will fit into your overall financial needs and plans. Did you know that title insurance, often offered in place of attorney representation, excepts from coverage matters disclosed, and those matters are not always standard? Unless you understand the law involving land titles in Florida, you cannot evaluate whether title is good and will be acceptable to a future purchaser. Additionally, there are many other professionals involved in a real estate purchase: the real estate broker whose job it is to put sellers and buyers together; the mortgage lender who provides financing to purchase the property; the appraiser who evaluates for the lender whether the value of the property is sufficient to support the loan; the surveyor who makes sure that the land being purchased is as set forth in the contract; the title agent, often a lawyer, who researches ownership of the property. Who better to advise you on all the aspects of a real estate transaction and the various determinations arrived at by these other professionals than your lawyer?',
                    ],
                    [
                        'question' => 'Are less expensive alternatives to litigation available to resolve disputes?',
                        'answer'   => 'Florida strongly endorses alternative methods of dispute resolution, such as arbitration and mediation. Many contracts are written to require the arbitration of disputes, and that is always a consideration when drafting any type of agreement. Arbitrations can be handled under the provisions of the Florida Arbitration Code or under the auspices of a private organization such as the American Arbitration Association. Mediation has recently gained a great deal of attention as an alternative to litigation. The courts of the Twentieth Judicial Circuit of Florida, which encompasses Lee, Collier, Charlotte, Hendry and Glades Counties, uniformly require mediation of a case before it goes to trial. Mediators are neutral parties whose role is to assist the disputing sides to resolve their differences themselves. Under rules promulgated by the Florida Supreme Court, mediators must complete a course of training and a mentorship program before being certified. While most mediations occur after a suit is filed, pre-suit mediation is an available alternative.',
                    ],
                ],
            ])
        )
        . $this->block('goetz/cta');
    }

    /**
     * @param array<string, mixed> $page
     */
    private function links_content(array $page): string
    {
        return $this->block('goetz/resource-links', [
            'groups'   => $this->resource_link_groups(),
            'imageUrl' => 'https://goetzlegal.com/wp-content/uploads/2022/08/law-firm-img.jpg',
            'imageAlt' => 'Lee County courthouse building',
        ])
        . $this->block('goetz/cta');
    }

    /**
     * @return array<int, array{heading: string, links: array<int, array{label: string, url: string}>}>
     */
    private function resource_link_groups(): array
    {
        return [
            [
                'heading' => 'Florida Links',
                'links'   => [
                    ['label' => 'Lee County Clerk of Courts', 'url' => 'https://www.leeclerk.org/'],
                    ['label' => 'Lee County Government', 'url' => 'https://www.leegov.com/'],
                    ['label' => 'Lee County Property Appraiser', 'url' => 'https://www.leepa.org/'],
                    ['label' => 'City of Fort Myers', 'url' => 'https://www.cityftmyers.com/'],
                    ['label' => 'Lee County Tax Collector', 'url' => 'https://www.leetc.com/'],
                    ['label' => 'Office of State Attorney', 'url' => 'https://sao.cjis20.org/'],
                    ['label' => 'Office of Public Defender', 'url' => 'https://publicdefender.cjis20.org/'],
                    ['label' => 'The 20th Judicial Circuit of Florida', 'url' => 'https://www.ca.cjis20.org/'],
                    ['label' => 'Office of the Sheriff', 'url' => 'https://www.sheriffleefl.org/'],
                    ['label' => 'Lee County Bar Association', 'url' => 'https://www.leebar.org/'],
                    ['label' => 'The Florida Bar', 'url' => 'https://www.floridabar.org/'],
                    ['label' => 'Florida Statutes and Constitution', 'url' => 'https://www.leg.state.fl.us/statutes/'],
                    ['label' => 'Florida Supreme Court', 'url' => 'https://www.floridasupremecourt.org/'],
                    ['label' => 'Florida Second District Court of Appeals', 'url' => 'https://www.2dca.org/'],
                    ['label' => 'Florida Corporations Information', 'url' => 'https://dos.myflorida.com/sunbiz/'],
                ],
            ],
            [
                'heading' => 'Federal Links',
                'links'   => [
                    ['label' => 'United States Supreme Court', 'url' => 'https://www.supremecourt.gov/'],
                    ['label' => 'Eleventh Circuit Court of Appeal', 'url' => 'https://www.ca11.uscourts.gov/'],
                    ['label' => 'The United States Bankruptcy Court for the Middle District of Florida', 'url' => 'https://www.flmb.uscourts.gov/'],
                    ['label' => 'United States District Court, Middle District of Florida', 'url' => 'https://www.flmd.uscourts.gov/'],
                ],
            ],
        ];
    }

    private function contact_content(): string
    {
        $form_shortcode = $this->ensure_contact_form_shortcode();

        return $this->section(
            '<div class="goetz-contact-grid">'
            . '<div>'
            . $this->heading('Contact Us')
            . '<!-- wp:shortcode -->' . $form_shortcode . '<!-- /wp:shortcode -->'
            . '</div>'
            . '<div>'
            . $this->heading('Contact Info.')
            . $this->paragraph('Address: ' . GOETZ_LEGAL_ADDRESS_LINE_1 . ', ' . GOETZ_LEGAL_ADDRESS_LINE_2)
            . $this->paragraph('Phone: ' . GOETZ_LEGAL_PHONE_DISPLAY)
            . $this->paragraph('Email: ' . GOETZ_LEGAL_EMAIL)
            . $this->paragraph('By providing my phone number to "Goetz & Goetz", I agree and acknowledge that "Goetz & Goetz" may send text messages to my wireless phone number for any purpose. Message and data rates may apply. Message frequency will vary, and you will be able to opt out by replying "STOP", assistance can be found by texting "HELP".')
            . $this->paragraph('Privacy and policy: No mobile information will be shared with third parties/affiliates for marketing/promotional purposes. All the above categories exclude text messaging originator opt-in data and consent; this information will not be shared with any third parties.')
            . '</div>'
            . '</div>'
        )
        . $this->block('goetz/cta');
    }

    private function ensure_contact_form_shortcode(): string
    {
        if (!post_type_exists('wpforms')) {
            return '[wpforms id="1" title="false" description="false"]';
        }

        $form_data = [
            'field_id' => 4,
            'fields'   => [
                1 => ['id' => 1, 'type' => 'name', 'label' => 'Name', 'format' => 'simple', 'required' => '1'],
                2 => ['id' => 2, 'type' => 'email', 'label' => 'E-Mail', 'required' => '1'],
                3 => ['id' => 3, 'type' => 'text', 'label' => 'Phone', 'required' => '1'],
                4 => ['id' => 4, 'type' => 'textarea', 'label' => 'Message', 'required' => '1'],
            ],
            'settings' => [
                'form_title'      => 'Goetz Contact Form',
                'submit_text'     => 'Send Message',
                'confirmation_type' => 'message',
                'confirmation_message' => 'Thank you for contacting Goetz & Goetz.',
            ],
        ];

        $existing = get_page_by_title('Goetz Contact Form', OBJECT, 'wpforms');

        if ($existing) {
            $form_data['id'] = (string) $existing->ID;
            wp_update_post([
                'ID'           => (int) $existing->ID,
                'post_content' => wp_json_encode($form_data),
            ]);
            return '[wpforms id="' . absint($existing->ID) . '" title="false" description="false"]';
        }

        $form_id = wp_insert_post([
            'post_type'    => 'wpforms',
            'post_status'  => 'publish',
            'post_title'   => 'Goetz Contact Form',
            'post_content' => wp_json_encode($form_data),
        ]);

        if (is_wp_error($form_id) || !$form_id) {
            return '[wpforms id="1" title="false" description="false"]';
        }

        $form_data['id'] = (string) $form_id;
        wp_update_post([
            'ID'           => (int) $form_id,
            'post_content' => wp_json_encode($form_data),
        ]);

        return '[wpforms id="' . absint($form_id) . '" title="false" description="false"]';
    }

    private function contact_form_needs_refresh(): bool
    {
        $existing = get_page_by_title('Goetz Contact Form', OBJECT, 'wpforms');

        if (!$existing) {
            return true;
        }

        $content = (string) get_post_field('post_content', (int) $existing->ID);

        return strpos($content, '"label":"Phone"') === false || strpos($content, '"type":"text"') === false;
    }

    /**
     * @return array<int, array{heading: string, links: array<int, array{label: string, url: string}>}>
     */
    private function extract_resource_groups(string $html): array
    {
        if (!$html) {
            return [];
        }

        $groups = [];
        $current = null;

        preg_match_all('/<(h2|h3)[^>]*>(.*?)<\/\1>|<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (!empty($match[1])) {
                $heading = trim(wp_strip_all_tags($match[2]));
                if (stripos($heading, 'Links') !== false) {
                    $current = ['heading' => $heading, 'links' => []];
                    $groups[] = $current;
                }
                continue;
            }

            if ($current === null || empty($match[3]) || empty($match[4])) {
                continue;
            }

            $index = count($groups) - 1;
            $groups[$index]['links'][] = [
                'label' => trim(wp_strip_all_tags($match[4])),
                'url'   => esc_url_raw(html_entity_decode($match[3])),
            ];
        }

        return array_values(array_filter($groups, static fn($group) => !empty($group['links'])));
    }

    private function block(string $name, array $attrs = []): string
    {
        $json = $attrs ? ' ' . wp_json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
        return '<!-- wp:' . $name . $json . ' /-->';
    }

    private function section(string $content, string $class = 'goetz-section'): string
    {
        return '<!-- wp:group {"className":"' . esc_attr($class) . '","layout":{"type":"constrained"}} --><div class="wp-block-group ' . esc_attr($class) . '">' . $content . '</div><!-- /wp:group -->';
    }

    private function html_block(string $html): string
    {
        return '<!-- wp:html -->' . $html . '<!-- /wp:html -->';
    }

    private function heading(string $text, int $level = 2): string
    {
        $level = max(2, min(4, $level));
        return '<!-- wp:heading {"level":' . $level . '} --><h' . $level . ' class="wp-block-heading">' . esc_html($text) . '</h' . $level . '><!-- /wp:heading -->';
    }

    private function paragraph(string $text): string
    {
        return '<!-- wp:paragraph --><p>' . esc_html($text) . '</p><!-- /wp:paragraph -->';
    }

    /**
     * @param array<int, string> $items
     */
    private function list(array $items): string
    {
        $html = '<!-- wp:list --><ul>';
        foreach ($items as $item) {
            $html .= '<li>' . esc_html($item) . '</li>';
        }
        return $html . '</ul><!-- /wp:list -->';
    }

    private function localize_remote_media(string $content, int $post_id): string
    {
        preg_match_all('#https?://(?:i\d\.wp\.com/)?goetzlegal\.com/wp-content/uploads/[^"\'\s<>)\\\\]+#i', $content, $matches);
        $urls = array_unique($matches[0] ?? []);

        foreach ($urls as $url) {
            $attachment_id = $this->sideload_media($url, $post_id);
            if ($attachment_id) {
                $local_url = wp_get_attachment_url($attachment_id);
                if ($local_url) {
                    $content = str_replace($url, $local_url, $content);
                }
            }
        }

        return $content;
    }

    private function sideload_media(string $url, int $post_id = 0): int
    {
        $source_url = $this->normalize_media_url($url);
        $existing = get_posts([
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => '_goetz_source_media_url',
            'meta_value'     => $source_url,
        ]);

        if (!empty($existing[0])) {
            return (int) $existing[0];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($source_url, 30);
        if (is_wp_error($tmp)) {
            return 0;
        }

        $path = wp_parse_url($source_url, PHP_URL_PATH);
        $name = $path ? basename($path) : 'goetz-media';
        $file = [
            'name'     => sanitize_file_name($name),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file, $post_id);

        if (is_wp_error($attachment_id)) {
            wp_delete_file($tmp);
            return 0;
        }

        update_post_meta((int) $attachment_id, '_goetz_source_media_url', $source_url);

        return (int) $attachment_id;
    }

    private function normalize_media_url(string $url): string
    {
        $url = html_entity_decode($url);
        $url = preg_replace('#^https?://i\d\.wp\.com/goetzlegal\.com/#i', 'https://goetzlegal.com/', $url) ?: $url;
        $parts = wp_parse_url($url);

        if (empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
            return $url;
        }

        return $parts['scheme'] . '://' . $parts['host'] . $parts['path'];
    }

    private function count_media_references(string $content): int
    {
        preg_match_all('#https?://(?:i\d\.wp\.com/)?goetzlegal\.com/wp-content/uploads/[^"\'\s<>)\\\\]+#i', $content, $matches);
        return count(array_unique($matches[0] ?? []));
    }

    /**
     * @param array<string, mixed> $page
     */
    private function import_yoast_meta(int $post_id, array $page): void
    {
        $yoast = isset($page['yoast']) && is_array($page['yoast']) ? $page['yoast'] : [];

        if (!empty($yoast['title'])) {
            update_post_meta($post_id, '_yoast_wpseo_title', wp_strip_all_tags((string) $yoast['title']));
        }

        if (!empty($yoast['description'])) {
            update_post_meta($post_id, '_yoast_wpseo_metadesc', wp_strip_all_tags((string) $yoast['description']));
        }

        if (!empty($yoast['canonical'])) {
            update_post_meta($post_id, '_yoast_wpseo_canonical', esc_url_raw(get_permalink($post_id)));
        }
    }

    /**
     * @param array<string, int> $imported_ids
     */
    private function configure_site(array $imported_ids): void
    {
        if (!empty($imported_ids['home'])) {
            update_option('show_on_front', 'page');
            update_option('page_on_front', (int) $imported_ids['home']);
            update_option('page_for_posts', 0);
        }

        $logo_id = $this->sideload_media('https://goetzlegal.com/wp-content/uploads/2022/08/GoetzLogo.png', 0);
        if ($logo_id) {
            set_theme_mod('custom_logo', $logo_id);
        }

        $this->rebuild_menu('Goetz Primary', 'primary', $imported_ids);
        $this->rebuild_menu('Goetz Footer', 'footer', $imported_ids);
        $this->cleanup_default_content();
        flush_rewrite_rules();
    }

    private function cleanup_default_content(): void
    {
        foreach (['sample-page', 'privacy-policy'] as $slug) {
            $page = get_page_by_path($slug, OBJECT, 'page');
            if ($page) {
                wp_delete_post((int) $page->ID, true);
            }
        }

        $post = get_page_by_path('hello-world', OBJECT, 'post');
        if ($post) {
            wp_delete_post((int) $post->ID, true);
        }
    }

    /**
     * @param array<string, int> $imported_ids
     */
    private function rebuild_menu(string $name, string $location, array $imported_ids): void
    {
        $menu = wp_get_nav_menu_object($name);
        $menu_id = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu($name);

        foreach (wp_get_nav_menu_items($menu_id) ?: [] as $item) {
            wp_delete_post((int) $item->ID, true);
        }

        foreach ($this->approved_pages as $path => $meta) {
            $slug = $meta['slug'];
            if (empty($imported_ids[$slug])) {
                continue;
            }

            $title = ($location === 'footer' && $slug === 'contact') ? 'Contact Us' : $meta['title'];

            wp_update_nav_menu_item($menu_id, 0, [
                'menu-item-title'     => $title,
                'menu-item-object-id' => (int) $imported_ids[$slug],
                'menu-item-object'    => 'page',
                'menu-item-type'      => 'post_type',
                'menu-item-status'    => 'publish',
            ]);
        }

        $locations = get_theme_mod('nav_menu_locations', []);
        $locations[$location] = $menu_id;
        set_theme_mod('nav_menu_locations', $locations);
    }
}
