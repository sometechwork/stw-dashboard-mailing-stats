<?php
/**
 * Editorial stats provider.
 *
 * @package STW_Dashboard_Mailing_Stats
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait STW_Dashboard_Mailing_Stats_Editorial {
	private function editorial_posts_payload( $start, $end, $page, $page_size, $search, $author_id, $category_id ) {
		$query_args = $this->editorial_query_args( $start, $end, $search, $author_id, $category_id );
		$query = new WP_Query(
			array_merge(
				$query_args,
				array(
					'posts_per_page' => $page_size,
					'paged'          => $page,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			)
		);

		$summary_ids = $this->editorial_post_ids( $query_args );
		$trend_start = gmdate( 'Y-m-01', strtotime( $end . ' -5 months' ) );
		$trend_ids = $this->editorial_post_ids( $this->editorial_query_args( $trend_start, $end, $search, $author_id, $category_id ) );
		$summary = $this->editorial_summary( $summary_ids );

		return array(
			'metrics'    => array(
				array( 'label' => 'Published posts', 'value' => (float) $query->found_posts, 'previous' => null, 'change' => null, 'format' => 'number' ),
				array( 'label' => 'Active authors', 'value' => (float) count( $summary['authors'] ), 'previous' => null, 'change' => null, 'format' => 'number' ),
				array( 'label' => 'Categories covered', 'value' => (float) count( $summary['categories'] ), 'previous' => null, 'change' => null, 'format' => 'number' ),
			),
			'timeseries' => $this->editorial_publishing_trend( $trend_ids, $end ),
			'breakdown'  => $this->editorial_author_breakdown( $summary['authors'] ),
			'rows'       => array_map( array( $this, 'editorial_post_row' ), is_array( $query->posts ) ? $query->posts : array() ),
			'pagination' => array(
				'page'       => $page,
				'pageSize'   => $page_size,
				'total'      => (int) $query->found_posts,
				'totalPages' => (int) max( 1, $query->max_num_pages ),
			),
		);
	}

	private function editorial_query_args( $start, $end, $search = '', $author_id = 0, $category_id = 0 ) {
		$args = array(
			'post_type'   => 'post',
			'post_status' => 'publish',
			'date_query'  => array(
				array(
					'after'     => $start . ' 00:00:00',
					'before'    => $end . ' 23:59:59',
					'inclusive' => true,
					'column'    => 'post_date_gmt',
				),
			),
		);
		if ( '' !== $search ) {
			$args['s'] = $search;
		}
		if ( $author_id > 0 ) {
			$args['author'] = $author_id;
		}
		if ( $category_id > 0 ) {
			$args['cat'] = $category_id;
		}
		return $args;
	}

	private function editorial_post_ids( array $base_args ) {
		$ids = array();
		$page = 1;
		do {
			$query = new WP_Query(
				array_merge(
					$base_args,
					array(
						'fields'         => 'ids',
						'posts_per_page' => 500,
						'paged'          => $page,
						'orderby'        => 'date',
						'order'          => 'DESC',
					)
				)
			);
			$ids = array_merge( $ids, array_map( 'absint', is_array( $query->posts ) ? $query->posts : array() ) );
			++$page;
		} while ( $page <= (int) $query->max_num_pages && $page <= 20 );

		return $ids;
	}

	private function editorial_summary( array $post_ids ) {
		$authors = array();
		$categories = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$author = $this->editorial_author_name( $post->post_author );
			$authors[ $author ] = ( $authors[ $author ] ?? 0 ) + 1;
			foreach ( $this->editorial_category_names( $post_id ) as $category ) {
				$categories[ $category ] = true;
			}
		}
		return array( 'authors' => $authors, 'categories' => $categories );
	}

	private function editorial_post_row( $post ) {
		$published = '0000-00-00 00:00:00' !== $post->post_date_gmt ? $post->post_date_gmt : $post->post_date;
		$modified = '0000-00-00 00:00:00' !== $post->post_modified_gmt ? $post->post_modified_gmt : $post->post_modified;
		return array(
			'id'            => (int) $post->ID,
			'title'         => $this->clean_text( get_the_title( $post ) ),
			'slug'          => (string) $post->post_name,
			'status'        => (string) $post->post_status,
			'publishedDate' => $this->iso_date( $published ),
			'modifiedDate'  => $this->iso_date( $modified ),
			'authorId'      => (int) $post->post_author,
			'author'        => $this->editorial_author_name( $post->post_author ),
			'categories'    => implode( ', ', $this->editorial_category_names( $post->ID ) ),
			'tags'          => implode( ', ', $this->editorial_tag_names( $post->ID ) ),
			'url'           => get_permalink( $post ),
		);
	}

	private function editorial_author_name( $author_id ) {
		$user = get_userdata( absint( $author_id ) );
		if ( ! $user ) {
			return __( 'Unknown', 'stw-dashboard-mailing-stats' );
		}
		$name = trim( (string) $user->display_name );
		if ( '' === $name ) {
			$name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
		}
		if ( '' === $name ) {
			$name = (string) $user->user_nicename;
		}
		return $this->clean_text( $name );
	}

	private function editorial_category_names( $post_id ) {
		$terms = get_the_category( $post_id );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_values(
			array_filter(
				array_map(
					function ( $term ) {
						return $this->clean_text( $term->name ?? '' );
					},
					$terms
				)
			)
		);
	}

	private function editorial_tag_names( $post_id ) {
		$terms = get_the_tags( $post_id );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		return array_values(
			array_filter(
				array_map(
					function ( $term ) {
						return $this->clean_text( $term->name ?? '' );
					},
					$terms
				)
			)
		);
	}

	private function editorial_author_breakdown( array $authors ) {
		arsort( $authors );
		$breakdown = array();
		foreach ( $authors as $label => $value ) {
			$breakdown[] = array( 'label' => $label, 'value' => (float) $value );
		}
		return $breakdown;
	}

	private function editorial_publishing_trend( array $post_ids, $end_date ) {
		$end = strtotime( $end_date . ' 12:00:00' );
		$months = array();
		for ( $index = 5; $index >= 0; --$index ) {
			$timestamp = strtotime( '-' . $index . ' months', $end );
			$key = gmdate( 'Y-m', $timestamp );
			$months[ $key ] = array(
				'date'  => gmdate( 'M Y', $timestamp ),
				'value' => 0,
			);
		}

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$key = gmdate( 'Y-m', strtotime( $post->post_date_gmt ?: $post->post_date ) );
			if ( isset( $months[ $key ] ) ) {
				$months[ $key ]['value'] += 1;
			}
		}

		$points = array_values( $months );
		foreach ( $points as $index => $point ) {
			$points[ $index ]['previous'] = 0 === $index ? 0 : (float) $points[ $index - 1 ]['value'];
			$points[ $index ]['value'] = (float) $point['value'];
		}
		return $points;
	}
}
