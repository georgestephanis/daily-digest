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
	const configuredProviders = Array.isArray( config.configuredProviders )
		? config.configuredProviders
		: [];
	const [ selectedProvider, setSelectedProvider ] = useState(
		configuredProviders[ 0 ]?.slug || ''
	);
	const [ providerDays, setProviderDays ] = useState( 1 );
	const [ queryLoading, setQueryLoading ] = useState( false );
	const [ queryError, setQueryError ] = useState( '' );
	const [ queryPayload, setQueryPayload ] = useState( null );
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

	const runProviderQuery = useCallback( async () => {
		if ( ! selectedProvider ) {
			setQueryPayload( null );
			setQueryError(
				__(
					'Select a configured provider before running a query.',
					'daily-digest'
				)
			);
			return;
		}

		setQueryLoading( true );
		setQueryError( '' );
		setQueryPayload( null );

		const response = await window.fetch(
			`${ config.restRoot }/providers/${ window.encodeURIComponent(
				selectedProvider
			) }/activity?days=${ window.encodeURIComponent(
				String( Math.max( 1, providerDays ) )
			) }`,
			{
				headers: {
					'X-WP-Nonce': config.restNonce,
				},
			}
		);

		let payload = null;
		try {
			payload = await response.json();
		} catch ( error ) {
			payload = null;
		}

		if ( ! response.ok || null === payload ) {
			setQueryError(
				__( 'Unable to query provider data.', 'daily-digest' )
			);
			setQueryLoading( false );
			return;
		}

		setQueryPayload( payload );
		setQueryLoading( false );
	}, [ config.restNonce, config.restRoot, providerDays, selectedProvider ] );

	useEffect( () => {
		void loadLogs();
	}, [ loadLogs ] );

	return (
		<div>
			<h3>{ __( 'Manual Provider Query', 'daily-digest' ) }</h3>
			<p>
				{ __(
					'Run a provider activity query and inspect the full JSON payload.',
					'daily-digest'
				) }
			</p>
			<div className="daily-digest-overview-controls">
				<label htmlFor="daily-digest-provider-query-provider">
					{ __( 'Provider:', 'daily-digest' ) }
				</label>
				<select
					id="daily-digest-provider-query-provider"
					value={ selectedProvider }
					onChange={ ( event ) =>
						setSelectedProvider( event.target.value )
					}
				>
					<option value="">
						{ __( 'Select provider', 'daily-digest' ) }
					</option>
					{ configuredProviders.map( ( provider ) => (
						<option key={ provider.slug } value={ provider.slug }>
							{ provider.name }
						</option>
					) ) }
				</select>
				<label htmlFor="daily-digest-provider-query-days">
					{ __( 'Days:', 'daily-digest' ) }
				</label>
				<input
					id="daily-digest-provider-query-days"
					type="number"
					min="1"
					max="30"
					value={ providerDays }
					onChange={ ( event ) => {
						const nextDays =
							Number.parseInt( event.target.value, 10 ) || 1;
						setProviderDays( Math.max( 1, nextDays ) );
					} }
				/>
				<Button
					variant="secondary"
					onClick={ () => void runProviderQuery() }
					disabled={ queryLoading }
				>
					{ __( 'Run Query', 'daily-digest' ) }
				</Button>
			</div>

			{ configuredProviders.length === 0 && (
				<p>
					{ __(
						'No configured and enabled providers were found for this user.',
						'daily-digest'
					) }
				</p>
			) }

			{ queryLoading && (
				<p>
					<Spinner /> { __( 'Querying provider…', 'daily-digest' ) }
				</p>
			) }

			{ ! queryLoading && queryError && <p>{ queryError }</p> }

			{ ! queryLoading && queryPayload && (
				<pre>{ JSON.stringify( queryPayload, null, 2 ) }</pre>
			) }

			<hr />
			<h3>{ __( 'Log Entries', 'daily-digest' ) }</h3>
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
