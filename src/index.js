import domReady from "@wordpress/dom-ready";
import {
  createRoot,
  useCallback,
  useEffect,
  useMemo,
  useState,
} from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import { Button, Spinner } from "@wordpress/components";
import { DataViews, filterSortAndPaginate } from "@wordpress/dataviews/wp";
import "./style.scss";

const defaultI18n = {
  digestTitle: __("Digest", "daily-digest"),
  daysLabel: __("Window (days):", "daily-digest"),
  refresh: __("Refresh Digest", "daily-digest"),
  loading: __("Loading activity…", "daily-digest"),
  loadError: __("Unable to load digest data.", "daily-digest"),
  noItems: __("No activity found for enabled providers.", "daily-digest"),
  time: __("Time", "daily-digest"),
  provider: __("Provider", "daily-digest"),
  type: __("Type", "daily-digest"),
  title: __("Title", "daily-digest"),
  summary: __("Summary", "daily-digest"),
  openItem: __("Open item", "daily-digest"),
};

const defaultLayouts = {
  table: {
    layout: {
      primaryField: "title",
    },
  },
  grid: {
    layout: {
      primaryField: "title",
    },
  },
};

const getDefaultView = () => ( {
  type: "table",
  perPage: 20,
  layout: defaultLayouts.table.layout,
  fields: [ "timestamp", "provider", "type", "title", "summary" ],
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
    return params.has( "days" );
  } catch ( error ) {
    return false;
  }
};

const loadInitialDays = ( config ) => {
  const configDays = Math.max( 1, Number.parseInt( config?.initialDays, 10 ) || 1 );

  if ( hasExplicitDaysQueryParam() ) {
    return configDays;
  }

  try {
    const storedValue = window.localStorage.getItem( buildDaysStorageKey( config ) );
    if ( ! storedValue ) {
      return configDays;
    }

    const parsed = JSON.parse( storedValue );
    const parsedDays = Number.parseInt( parsed?.days, 10 );

    if ( parsedDays > 1 ) {
      return Math.min( 30, parsedDays );
    }
  } catch ( error ) {
  }

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
    if ( ! parsed || typeof parsed !== "object" ) {
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

const App = ({ config }) => {
  const i18n = { ...defaultI18n, ...(config.i18n || {}) };
  const [days, setDays] = useState(() => loadInitialDays(config));
  const [rawItems, setRawItems] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState("");
  const [view, setView] = useState(() => loadPersistedView(config));

  const fields = useMemo(
    () => [
      {
        id: "timestamp",
        label: i18n.time,
        enableGlobalSearch: true,
      },
      {
        id: "provider",
        label: i18n.provider,
        enableGlobalSearch: true,
      },
      {
        id: "type",
        label: i18n.type,
        enableGlobalSearch: true,
      },
      {
        id: "title",
        label: i18n.title,
        enableGlobalSearch: true,
        render: ({ item }) => {
          if (item.url) {
            return (
              <a href={item.url} target="_blank" rel="noopener noreferrer">
                {item.title || ""}
              </a>
            );
          }
          return item.title || "";
        },
      },
      {
        id: "summary",
        label: i18n.summary,
        enableGlobalSearch: true,
      },
    ],
    [i18n],
  );

  const loadDigest = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage("");

    const response = await window.fetch(
      `${config.restRoot}/digest?days=${window.encodeURIComponent(String(Math.max(1, days)))}`,
      {
        headers: {
          "X-WP-Nonce": config.restNonce,
        },
      },
    );

    if (!response.ok) {
      setRawItems([]);
      setErrorMessage(i18n.loadError);
      setIsLoading(false);
      return;
    }

    const payload = await response.json();
    setRawItems(Array.isArray(payload.items) ? payload.items : []);
    setIsLoading(false);
  }, [config.restNonce, config.restRoot, days, i18n.loadError]);

  const actions = useMemo(
    () => [
      {
        id: "open-item",
        label: i18n.openItem,
        isEligible: (item) => Boolean(item?.url),
        callback: ([item]) => {
          if (item?.url) {
            window.open(item.url, "_blank", "noopener,noreferrer");
          }
        },
      },
    ],
    [i18n.openItem],
  );

  const { data: processedData, paginationInfo } = useMemo(
    () => filterSortAndPaginate(rawItems, view, fields),
    [fields, rawItems, view],
  );

  useEffect(() => {
    void loadDigest();
  }, [loadDigest]);

  useEffect(() => {
    try {
      const key = buildViewStorageKey(config);
      window.localStorage.setItem(key, JSON.stringify(view));
    } catch (error) {
    }
  }, [config, view]);

  useEffect(() => {
    try {
      const key = buildDaysStorageKey(config);

      if (days > 1) {
        window.localStorage.setItem(
          key,
          JSON.stringify({
            days,
          }),
        );
        return;
      }

      window.localStorage.removeItem(key);
    } catch (error) {
    }
  }, [config, days]);

  return (
    <div>
      <h2>{i18n.digestTitle}</h2>
      <div className="daily-digest-overview-controls">
        <label htmlFor="daily-digest-days-input">{i18n.daysLabel}</label>
        <input
          id="daily-digest-days-input"
          className="daily-digest-overview-days"
          type="number"
          min="1"
          max="30"
          value={days}
          onChange={(event) => {
            const nextDays = Number.parseInt(event.target.value, 10) || 1;
            setDays(Math.max(1, nextDays));
          }}
        />
        <Button variant="secondary" onClick={() => void loadDigest()}>
          {i18n.refresh}
        </Button>
      </div>

      {isLoading && (
        <p>
          <Spinner /> {i18n.loading}
        </p>
      )}

      {!isLoading && errorMessage && <p>{errorMessage}</p>}
      {!isLoading && !errorMessage && rawItems.length === 0 && (
        <p>{i18n.noItems}</p>
      )}

      {!isLoading && !errorMessage && rawItems.length > 0 && (
        <DataViews
          data={processedData}
          fields={fields}
          view={view}
          onChangeView={setView}
          defaultLayouts={defaultLayouts}
          actions={actions}
          paginationInfo={paginationInfo}
        />
      )}
    </div>
  );
};

domReady(() => {
  const target = document.getElementById("daily-digest-overview-app");
  if (!target) {
    return;
  }

  const root = createRoot(target);
  root.render(<App config={window.dailyDigestOverviewConfig || {}} />);
});
