import domReady from '@wordpress/dom-ready';
import {
	createRoot,
	useCallback,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews/wp';
import { LogViewerApp } from './log-viewer-app';
import { initSettingsPage } from './settings-page';
import slackLogo from './assets/provider-logos/slack.svg';
import clickupLogo from './assets/provider-logos/clickup.svg';
import githubLogo from './assets/provider-logos/github.svg';
import './style.scss';

const defaultLayouts = {
	table: {
		layout: {
			primaryField: 'title',
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

const buildDaysStorageKey = ( config ) => {
	const userId = Number.parseInt( config?.currentUser, 10 ) || 0;
	return `dailyDigestOverviewDays:${ userId }`;
};

const hasExplicitDaysQueryParam = () => {
	try {
		const params = new URLSearchParams( window.location.search );
		return params.has( 'days' );
	} catch ( error ) {
		return false;
	}
};

const loadInitialDays = ( config ) => {
	const configDays = Math.max(
		1,
		Number.parseInt( config?.initialDays, 10 ) || 1
	);

	if ( hasExplicitDaysQueryParam() ) {
		return configDays;
	}

	try {
		const storedValue = window.localStorage.getItem(
			buildDaysStorageKey( config )
		);
		if ( ! storedValue ) {
			return configDays;
		}

		const parsed = JSON.parse( storedValue );
		const parsedDays = Number.parseInt( parsed?.days, 10 );

		if ( parsedDays > 1 ) {
			return Math.min( 30, parsedDays );
		}
	} catch ( error ) {}

	return configDays;
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
			fields: Array.isArray( parsed.fields )
				? parsed.fields
				: getDefaultView().fields,
		};
	} catch ( error ) {
		return getDefaultView();
	}
};

const App = ( { config } ) => {
	const [ days, setDays ] = useState( () => loadInitialDays( config ) );
	const [ rawItems, setRawItems ] = useState( [] );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ errorMessage, setErrorMessage ] = useState( '' );
	const [ view, setView ] = useState( () => loadPersistedView( config ) );

	const fields = useMemo(
		() => [
			{
				id: 'timestamp',
				label: __( 'Time', 'daily-digest' ),
				enableGlobalSearch: true,
				render: ( { item } ) => formatTimestamp( item.timestamp ),
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
			`${ config.restRoot }/digest?days=${ window.encodeURIComponent(
				String( Math.max( 1, days ) )
			) }`,
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
			return;
		}

		const payload = await response.json();
		setRawItems( Array.isArray( payload.items ) ? payload.items : [] );
		setIsLoading( false );
	}, [ config.restNonce, config.restRoot, days ] );

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

	const { data: processedData, paginationInfo } = useMemo(
		() => filterSortAndPaginate( rawItems, view, fields ),
		[ fields, rawItems, view ]
	);

	useEffect( () => {
		void loadDigest();
	}, [ loadDigest ] );

	useEffect( () => {
		try {
			const key = buildViewStorageKey( config );
			window.localStorage.setItem( key, JSON.stringify( view ) );
		} catch ( error ) {}
	}, [ config, view ] );

	useEffect( () => {
		try {
			const key = buildDaysStorageKey( config );

			if ( days > 1 ) {
				window.localStorage.setItem(
					key,
					JSON.stringify( {
						days,
					} )
				);
				return;
			}

			window.localStorage.removeItem( key );
		} catch ( error ) {}
	}, [ config, days ] );

	return (
		<div>
			<h2>{ __( 'Digest', 'daily-digest' ) }</h2>
			<div className="daily-digest-overview-controls">
				<label htmlFor="daily-digest-days-input">
					{ __( 'Window (days):', 'daily-digest' ) }
				</label>
				<input
					id="daily-digest-days-input"
					className="daily-digest-overview-days"
					type="number"
					min="1"
					max="30"
					value={ days }
					onChange={ ( event ) => {
						const nextDays =
							Number.parseInt( event.target.value, 10 ) || 1;
						setDays( Math.max( 1, nextDays ) );
					} }
				/>
				<Button variant="secondary" onClick={ () => void loadDigest() }>
					{ __( 'Refresh Digest', 'daily-digest' ) }
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
