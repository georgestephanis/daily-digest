import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';

const formatTimestamp = ( value ) => {
	if ( ! value ) {
		return '';
	}

	const parsed = new Date( value );
	if ( Number.isNaN( parsed.getTime() ) ) {
		return value;
	}

	return parsed.toLocaleString( undefined, {
		dateStyle: 'medium',
		timeStyle: 'medium',
	} );
};

export const LogViewerApp = ( { config } ) => {
	const [ rawItems, setRawItems ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ errorMessage, setErrorMessage ] = useState( '' );
	const [ view, setView ] = useState( {
		type: 'table',
		perPage: 25,
		fields: [
			'timestamp',
			'provider',
			'context',
			'method',
			'status',
			'url',
			'response_summary',
			'summary',
		],
		sort: {
			field: 'timestamp',
			direction: 'desc',
		},
	} );

	const fields = useMemo(
		() => [
			{
				id: 'timestamp',
				label: __( 'Timestamp', 'daily-digest' ),
				enableGlobalSearch: true,
				render: ( { item } ) => formatTimestamp( item.timestamp ),
			},
			{
				id: 'provider',
				label: __( 'Provider', 'daily-digest' ),
				enableGlobalSearch: true,
			},
			{
				id: 'context',
				label: __( 'Context', 'daily-digest' ),
				enableGlobalSearch: true,
			},
			{
				id: 'method',
				label: __( 'Method', 'daily-digest' ),
				enableGlobalSearch: true,
			},
			{
				id: 'status',
				label: __( 'Status', 'daily-digest' ),
				enableGlobalSearch: true,
			},
			{
				id: 'url',
				label: __( 'URL', 'daily-digest' ),
				enableGlobalSearch: true,
				render: ( { item } ) => {
					if ( ! item.url ) {
						return '';
					}

					return (
						<a
							href={ item.url }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ item.url }
						</a>
					);
				},
			},
			{
				id: 'response_summary',
				label: __( 'Response', 'daily-digest' ),
				enableGlobalSearch: true,
			},
			{
				id: 'summary',
				label: __( 'Summary', 'daily-digest' ),
				enableGlobalSearch: true,
			},
		],
		[]
	);

	const loadLogs = useCallback( async () => {
		setIsLoading( true );
		setErrorMessage( '' );

		const response = await window.fetch(
			`${ config.restRoot }/logs?limit=500`,
			{
				headers: {
					'X-WP-Nonce': config.restNonce,
				},
			}
		);

		if ( ! response.ok ) {
			setRawItems( [] );
			setErrorMessage( __( 'Unable to load logs.', 'daily-digest' ) );
			setIsLoading( false );
			return;
		}

		const payload = await response.json();
		setRawItems( Array.isArray( payload.items ) ? payload.items : [] );
		setIsLoading( false );
	}, [ config.restNonce, config.restRoot ] );

	const actions = useMemo(
		() => [
			{
				id: 'open-log-url',
				label: __( 'Open URL', 'daily-digest' ),
				isEligible: ( item ) => Boolean( item?.url ),
				callback: ( [ item ] ) => {
					if ( item?.url ) {
						window.open(
							item.url,
							'_blank',
							'noopener,noreferrer'
						);
					}
				},
			},
		],
		[]
	);

	const { data: processedData, paginationInfo } = useMemo(
		() => filterSortAndPaginate( rawItems, view, fields ),
		[ fields, rawItems, view ]
	);

	useEffect( () => {
		void loadLogs();
	}, [ loadLogs ] );

	return (
		<div>
			<div className="daily-digest-overview-controls">
				<Button variant="secondary" onClick={ () => void loadLogs() }>
					{ __( 'Refresh Logs', 'daily-digest' ) }
				</Button>
			</div>

			{ isLoading && (
				<p>
					<Spinner /> { __( 'Loading logs…', 'daily-digest' ) }
				</p>
			) }

			{ ! isLoading && errorMessage && <p>{ errorMessage }</p> }
			{ ! isLoading && ! errorMessage && rawItems.length === 0 && (
				<p>{ __( 'No log entries found.', 'daily-digest' ) }</p>
			) }

			{ ! isLoading && ! errorMessage && rawItems.length > 0 && (
				<DataViews
					data={ processedData }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					defaultLayouts={ { table: { layout: {} } } }
					actions={ actions }
					paginationInfo={ paginationInfo }
				/>
			) }
		</div>
	);
};
