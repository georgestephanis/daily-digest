import domReady from '@wordpress/dom-ready';
import {
	createRoot,
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { LogViewerApp } from './log-viewer-app';
import { initSettingsPage } from './settings-page';
import slackLogo from './assets/provider-logos/slack.svg';
import clickupLogo from './assets/provider-logos/clickup.svg';
import githubLogo from './assets/provider-logos/github.svg';
import harvestLogo from './assets/provider-logos/harvest.svg';
import './style.scss';

const defaultLayouts = {
	table: {
		layout: {
			primaryField: 'title',
			styles: {
				provider: {
					align: 'center',
					width: '68px',
					minWidth: '68px',
					maxWidth: '68px',
				},
			},
		},
	},
	grid: {
		layout: {
			primaryField: 'title',
		},
	},
};

const providerLogos = {
	slack: {
		name: 'Slack',
		src: slackLogo,
	},
	clickup: {
		name: 'ClickUp',
		src: clickupLogo,
	},
	github: {
		name: 'GitHub',
		src: githubLogo,
	},
	harvest: {
		name: 'Harvest',
		src: harvestLogo,
	},
};

const renderProviderIcon = ( provider ) => {
	const normalizedProvider = String( provider || '' )
		.toLowerCase()
		.replace( /\s+/g, '' );
	const logo = providerLogos[ normalizedProvider ];

	if ( logo ) {
		return (
			<span
				className="daily-digest-provider-icon-cell"
				title={ logo.name }
				aria-label={ logo.name }
			>
				<span className="daily-digest-provider-icon">
					<img src={ logo.src } alt="" aria-hidden="true" />
				</span>
			</span>
		);
	}

	const providerLabel = String( provider || '' );
	const fallbackText = providerLabel.slice( 0, 1 ).toUpperCase() || '?';

	return (
		<span
			className="daily-digest-provider-icon-cell"
			title={ providerLabel }
			aria-label={ providerLabel }
		>
			<span className="daily-digest-provider-icon daily-digest-provider-icon-fallback">
				{ fallbackText }
			</span>
		</span>
	);
};

const formatTimestamp = ( value ) => {
	if ( ! value ) {
		return { timeWithZone: '', dateLabel: '' };
	}

	const parsed = new Date( value );
	if ( Number.isNaN( parsed.getTime() ) ) {
		return {
			timeWithZone: value,
			dateLabel: '',
		};
	}

	const timeWithZone = parsed.toLocaleTimeString( undefined, {
		hour: 'numeric',
		minute: '2-digit',
		timeZoneName: 'short',
	} );

	const dateLabel = parsed.toLocaleDateString( undefined, {
		month: 'short',
		day: 'numeric',
		year: 'numeric',
	} );

	return {
		timeWithZone,
		dateLabel,
	};
};

const getDefaultView = () => ( {
	type: 'table',
	perPage: 20,
	layout: defaultLayouts.table.layout,
	fields: [ 'timestamp', 'provider', 'type', 'title', 'summary' ],
} );

const buildViewStorageKey = ( config ) => {
	const userId = Number.parseInt( config?.currentUser, 10 ) || 0;
	return `dailyDigestOverviewView:${ userId }`;
};

const buildDateStorageKey = ( config ) => {
	const userId = Number.parseInt( config?.currentUser, 10 ) || 0;
	return `dailyDigestOverviewDates:${ userId }`;
};

const getTodayDate = ( config ) => {
	if (
		config?.initialDate &&
		/^\d{4}-\d{2}-\d{2}$/.test( config.initialDate )
	) {
		return config.initialDate;
	}
	return new Date().toISOString().slice( 0, 10 );
};

const getDatePlusDays = ( dateStr, days ) => {
	const date = new Date( dateStr + 'T00:00:00' );
	date.setDate( date.getDate() + days );
	return date.toISOString().slice( 0, 10 );
};

const loadInitialDates = ( config ) => {
	try {
		const storedValue = window.localStorage.getItem(
			buildDateStorageKey( config )
		);
		if ( storedValue ) {
			const parsed = JSON.parse( storedValue );
			if (
				parsed?.startDate &&
				/^\d{4}-\d{2}-\d{2}$/.test( parsed.startDate )
			) {
				const startDate = parsed.startDate;
				const endDate =
					parsed.endDate &&
					/^\d{4}-\d{2}-\d{2}$/.test( parsed.endDate )
						? parsed.endDate
						: startDate;
				const isRange = Boolean( parsed.isRange );
				return { startDate, endDate, isRange };
			}
		}
	} catch ( error ) {}

	const today = getTodayDate( config );
	return { startDate: today, endDate: today, isRange: false };
};

const loadPersistedView = ( config ) => {
	try {
		const key = buildViewStorageKey( config );
		const value = window.localStorage.getItem( key );

		if ( ! value ) {
			return getDefaultView();
		}

		const parsed = JSON.parse( value );
		if ( ! parsed || typeof parsed !== 'object' ) {
			return getDefaultView();
		}

		return {
			...getDefaultView(),
			...parsed,
			layout: {
				...getDefaultView().layout,
				...( parsed.layout || {} ),
				styles: {
					...( getDefaultView().layout?.styles || {} ),
					...( parsed.layout?.styles || {} ),
				},
			},
			fields: Array.isArray( parsed.fields )
				? parsed.fields
				: getDefaultView().fields,
		};
	} catch ( error ) {
		return getDefaultView();
	}
};

const App = ( { config } ) => {
	const [ { startDate, endDate, isRange }, setDates ] = useState( () =>
		loadInitialDates( config )
	);
	const [ rawItems, setRawItems ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ errorMessage, setErrorMessage ] = useState( '' );
	const [ view, setView ] = useState( () => loadPersistedView( config ) );
	const [ hasLoaded, setHasLoaded ] = useState( false );
	const [ cooldownRemaining, setCooldownRemaining ] = useState( 0 );
	const isInitialLoad = useRef( true );
	const debounceTimerRef = useRef( null );
	const cooldownIntervalRef = useRef( null );

	const fields = useMemo(
		() => [
			{
				id: 'timestamp',
				label: __( 'Time', 'daily-digest' ),
				enableGlobalSearch: true,
				render: ( { item } ) => {
					const { timeWithZone, dateLabel } = formatTimestamp(
						item.timestamp
					);

					return (
						<span className="daily-digest-overview-time-cell">
							<span className="daily-digest-overview-time-main">
								{ timeWithZone }
							</span>
							{ dateLabel && (
								<span className="daily-digest-overview-time-date">
									{ dateLabel }
								</span>
							) }
						</span>
					);
				},
			},
			{
				id: 'provider',
				label: __( 'Provider', 'daily-digest' ),
				enableGlobalSearch: true,
				render: ( { item } ) => renderProviderIcon( item.provider ),
			},
			{
				id: 'type',
				label: __( 'Type', 'daily-digest' ),
				enableGlobalSearch: true,
			},
			{
				id: 'title',
				label: __( 'Title', 'daily-digest' ),
				enableGlobalSearch: true,
				render: ( { item } ) => {
					if ( item.url ) {
						return (
							<span className="daily-digest-overview-title">
								<a
									href={ item.url }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ item.title || '' }
								</a>
							</span>
						);
					}
					return (
						<span className="daily-digest-overview-title">
							{ item.title || '' }
						</span>
					);
				},
			},
			{
				id: 'summary',
				label: __( 'Summary', 'daily-digest' ),
				enableGlobalSearch: true,
				render: ( { item } ) => (
					<span className="daily-digest-overview-summary">
						{ item.summary || '' }
					</span>
				),
			},
		],
		[]
	);

	const loadDigest = useCallback( async () => {
		setIsLoading( true );
		setErrorMessage( '' );

		const response = await window.fetch(
			`${
				config.restRoot
			}/digest?start_date=${ window.encodeURIComponent(
				startDate
			) }&end_date=${ window.encodeURIComponent( endDate ) }`,
			{
				headers: {
					'X-WP-Nonce': config.restNonce,
				},
			}
		);

		if ( ! response.ok ) {
			setRawItems( [] );
			setErrorMessage(
				__( 'Unable to load digest data.', 'daily-digest' )
			);
			setIsLoading( false );
			setHasLoaded( true );
			return;
		}

		const payload = await response.json();
		setRawItems( Array.isArray( payload.items ) ? payload.items : [] );
		setIsLoading( false );
		setHasLoaded( true );
	}, [ config.restNonce, config.restRoot, startDate, endDate ] );

	const actions = useMemo(
		() => [
			{
				id: 'open-item',
				label: __( 'Open item', 'daily-digest' ),
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

	const handleManualRefresh = useCallback( async () => {
		// Cancel any pending debounced fetch so it doesn't fire after the manual one.
		if ( debounceTimerRef.current ) {
			clearTimeout( debounceTimerRef.current );
			debounceTimerRef.current = null;
		}

		// Start 60-second cooldown.
		setCooldownRemaining( 60 );
		if ( cooldownIntervalRef.current ) {
			clearInterval( cooldownIntervalRef.current );
		}
		cooldownIntervalRef.current = setInterval( () => {
			setCooldownRemaining( ( prev ) => {
				if ( prev <= 1 ) {
					clearInterval( cooldownIntervalRef.current );
					cooldownIntervalRef.current = null;
					return 0;
				}
				return prev - 1;
			} );
		}, 1000 );

		// Clear server-side cache, then reload.
		try {
			await window.fetch( `${ config.restRoot }/digest/cache`, {
				method: 'DELETE',
				headers: { 'X-WP-Nonce': config.restNonce },
			} );
		} catch ( _err ) {
			// Proceed even if the cache-clear request fails.
		}

		void loadDigest();
	}, [ config.restRoot, config.restNonce, loadDigest ] );

	const { data: processedData, paginationInfo } = useMemo(
		() => filterSortAndPaginate( rawItems, view, fields ),
		[ fields, rawItems, view ]
	);

	useEffect( () => {
		const delay = isInitialLoad.current ? 0 : 2000;
		isInitialLoad.current = false;
		debounceTimerRef.current = setTimeout( () => {
			debounceTimerRef.current = null;
			void loadDigest();
		}, delay );
		return () => {
			clearTimeout( debounceTimerRef.current );
			debounceTimerRef.current = null;
		};
	}, [ loadDigest ] );

	useEffect( () => {
		try {
			const key = buildViewStorageKey( config );
			window.localStorage.setItem( key, JSON.stringify( view ) );
		} catch ( error ) {}
	}, [ config, view ] );

	useEffect( () => {
		try {
			const key = buildDateStorageKey( config );
			window.localStorage.setItem(
				key,
				JSON.stringify( { startDate, endDate, isRange } )
			);
		} catch ( error ) {}
	}, [ config, startDate, endDate, isRange ] );

	return (
		<div>
			<h2>{ __( 'Digest', 'daily-digest' ) }</h2>
			<div className="daily-digest-overview-controls">
				<label htmlFor="daily-digest-start-date">
					{ isRange
						? __( 'From:', 'daily-digest' )
						: __( 'Date:', 'daily-digest' ) }
				</label>
				<input
					id="daily-digest-start-date"
					className="daily-digest-overview-date"
					type="date"
					value={ startDate }
					onChange={ ( event ) => {
						const newStart = event.target.value;
						if ( ! newStart ) {
							return;
						}
						const maxEnd = getDatePlusDays( newStart, 6 );
						let newEnd = isRange ? endDate : newStart;
						if ( newEnd < newStart ) {
							newEnd = newStart;
						} else if ( newEnd > maxEnd ) {
							newEnd = maxEnd;
						}
						setDates( {
							startDate: newStart,
							endDate: newEnd,
							isRange,
						} );
					} }
				/>
				{ isRange && (
					<>
						<label htmlFor="daily-digest-end-date">
							{ __( 'To:', 'daily-digest' ) }
						</label>
						<input
							id="daily-digest-end-date"
							className="daily-digest-overview-date"
							type="date"
							value={ endDate }
							min={ startDate }
							max={ getDatePlusDays( startDate, 6 ) }
							onChange={ ( event ) => {
								const newEnd = event.target.value;
								if ( ! newEnd ) {
									return;
								}
								setDates( {
									startDate,
									endDate: newEnd,
									isRange,
								} );
							} }
						/>
					</>
				) }
				<label
					htmlFor="daily-digest-range-toggle"
					className="daily-digest-range-toggle"
				>
					<input
						id="daily-digest-range-toggle"
						type="checkbox"
						checked={ isRange }
						onChange={ ( event ) => {
							const nowRange = event.target.checked;
							setDates( {
								startDate,
								endDate: nowRange ? endDate : startDate,
								isRange: nowRange,
							} );
						} }
					/>
					{ __( 'Date range', 'daily-digest' ) }
				</label>
				<Button
					variant="secondary"
					onClick={ () => void handleManualRefresh() }
					disabled={ ! hasLoaded || cooldownRemaining > 0 }
				>
					{ cooldownRemaining > 0
						? sprintf(
								/* translators: %d: seconds until the button re-enables. */
								__( 'Refresh (%ds)', 'daily-digest' ),
								cooldownRemaining
						  )
						: __( 'Refresh', 'daily-digest' ) }
				</Button>
			</div>

			{ isLoading && (
				<p>
					<Spinner /> { __( 'Loading activity…', 'daily-digest' ) }
				</p>
			) }

			{ ! isLoading && errorMessage && <p>{ errorMessage }</p> }
			{ ! isLoading && ! errorMessage && rawItems.length === 0 && (
				<p>
					{ __(
						'No activity found for enabled providers.',
						'daily-digest'
					) }
				</p>
			) }

			{ ! isLoading && ! errorMessage && rawItems.length > 0 && (
				<DataViews
					data={ processedData }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					defaultLayouts={ defaultLayouts }
					actions={ actions }
					paginationInfo={ paginationInfo }
				/>
			) }
		</div>
	);
};

domReady( () => {
	const overviewTarget = document.getElementById(
		'daily-digest-overview-app'
	);
	const overviewConfig = window.dailyDigestOverviewConfig || {};

	if ( overviewTarget ) {
		const root = createRoot( overviewTarget );
		root.render( <App config={ overviewConfig } /> );
	}

	const logViewerTarget = document.getElementById(
		'daily-digest-log-viewer-app'
	);
	const logViewerConfig = window.dailyDigestLogsPageConfig || {};

	if ( logViewerTarget ) {
		const logViewerRoot = createRoot( logViewerTarget );
		logViewerRoot.render( <LogViewerApp config={ logViewerConfig } /> );
	}

	initSettingsPage();
} );
