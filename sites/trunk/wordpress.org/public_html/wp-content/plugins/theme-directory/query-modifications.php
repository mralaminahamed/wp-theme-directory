<?php

/**
 * Correct the post type for theme queries to be "repopackage".
 */
function wporg_themes_pre_get_posts( $query ) {
	if ( is_admin() ) {
		return;
	}

	// Don't apply this to non-theme queries
	if ( !empty( $query->query_vars['post_type'] ) && 'repopackage' != $query->query_vars['post_type'] ) {
		return;
	}
	// Themes are never via pagename
	if ( !empty( $query->query_vars['pagename'] ) ) {
		return;
	}

	// Default to the featured view
	if ( empty( $query->query ) ) {
		$query->query_vars['browse'] = 'featured';
	}

	// From now on, always query themes.
	$query->query_vars['post_type'] = 'repopackage';
	if ( ! isset( $query->query_vars['browse'] ) ) {
		$query->query_vars['browse'] = '';
	}

	// Force the browse query_var when querying for a users favorites
	if ( !empty( $query->query_vars['favorites_user'] ) ) {
		$query->query_vars['browse'] = 'favorites';
	}

	switch ( $query->query_vars['browse'] ) {
		case 'new':
			$query->query_vars['orderby'] = 'ID';
			$query->query_vars['order'] = 'DESC';
			break;

		case 'updated':
			$query->query_vars['orderby'] = 'modified';
			$query->query_vars['order'] = 'DESC';
			break;

		case 'featured':
			// Pages > 1 don't exist.
			if ( isset( $query->query_vars['paged'] ) && $query->query_vars['paged'] > 1 ) {
				// Force a 404
				$query->query_vars['post__in'] = array( 0 );
			}

			$query->query_vars['orderby'] = 'RAND(' . date( 'Ymd' ) . ')';
			$query->query_vars['posts_per_page'] = $query->found_posts = 15;
			// Featured themes require it to have been updated within the last year, not the default 2.
			$query->query_vars['date_query']['recent_themes_only'] = array(
				'column' => 'post_modified_gmt',
				'after'  => date( 'Y-m-d', strtotime( '-1 year' ) )
			);
			$query->query_vars['no_found_rows'] = true;
			break;

		case 'favorites':
			$favorites = array();

			if ( !empty( $query->query_vars['favorites_user'] ) ) {
				$user_id = ( new WP_User( $query->query_vars['favorites_user'] ) )->ID;
			} elseif ( is_user_logged_in() ) {
				$user_id = get_current_user_id();
			}

			if ( ! empty( $user_id ) ) {
				$favorites = array_filter( (array) get_user_meta( $user_id, 'theme_favorites', true ) );
			}

			if ( $favorites ) {
				$query->query_vars['post_name__in'] = $favorites;
			} else {
				// Force a 404
				$query->query_vars['post__in'] = array( 0 );
			}

			$query->query_vars['orderby'] = 'post_title';
			$query->query_vars['order'] = 'ASC';
			break;

		case 'popular':
			// Only include themes that have existed for at least 2 weeks into the popular listing
			// This avoids cases where a new theme skews our popularity algorithms.
			$query->query_vars['date_query']['existing_themes_only'] = array(
				'column' => 'post_date_gmt',
				'before'  => date( 'Y-m-d', strtotime( '-2 weeks' ) )
			);

			// Sort by the popularity meta key
			$query->query_vars['meta_key'] = '_popularity';
			$query->query_vars['meta_type'] = 'DECIMAL';
			$query->query_vars['orderby'] = 'meta_value';
			break;
	}

	// Unless a specific theme, or author is being requested, limit results to the last 2 years.
	if ( empty( $query->query_vars['name'] ) && empty( $query->query_vars['author_name'] ) && ! in_array( $query->query_vars['browse'], array( 'favorites', 'new', 'updated' ) ) ) {
		$query->query_vars['date_query']['recent_themes_only'] = array(
			'column' => 'post_modified_gmt',
			'after'  => date( 'Y-m-d', strtotime( '-2 years' ) ),
		);
	}

	// Prioritize translated themes for localized requests, except when viewing a specific ordered themes.
	if ( 'en_US' !== get_locale() && ! in_array( $query->query_vars['browse'], array( 'favorites', 'new', 'updated' ) )  ) {
		add_filter( 'posts_clauses', 'wporg_themes_prioritize_translated_posts_clauses' );
	}

}
add_action( 'pre_get_posts', 'wporg_themes_pre_get_posts' );

/**
 * Filters SQL clauses, to prioritize translated themes.
 *
 * @param array $clauses
 *
 * @return array
 */
function wporg_themes_prioritize_translated_posts_clauses( $clauses ) {
	global $wpdb;

	$clauses['groupby']  = "{$wpdb->posts}.ID";
	$clauses['join']    .= $wpdb->prepare( " LEFT JOIN language_packs AS l ON ( {$wpdb->posts}.post_name = l.domain AND l.active=1 AND l.type='theme' AND l.language=%s )", get_locale() );
	$clauses['orderby']  = 'l.domain IS NOT NULL DESC, ' . $clauses['orderby'];

	return $clauses;
}
