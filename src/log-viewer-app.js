import { useCallback, useEffect, useMemo, useState } from "@wordpress/element";
import { __ } from "@wordpress/i18n";
import { Button, Spinner } from "@wordpress/components";
import { DataViews, filterSortAndPaginate } from "@wordpress/dataviews/wp";

const defaultLogI18n = {
  refresh: __("Refresh Logs", "daily-digest"),
  loading: __("Loading logs…", "daily-digest"),
  loadError: __("Unable to load logs.", "daily-digest"),
  noLogs: __("No log entries found.", "daily-digest"),
  timestamp: __("Timestamp", "daily-digest"),
  provider: __("Provider", "daily-digest"),
  context: __("Context", "daily-digest"),
  method: __("Method", "daily-digest"),
  status: __("Status", "daily-digest"),
  url: __("URL", "daily-digest"),
  summary: __("Summary", "daily-digest"),
  openUrl: __("Open URL", "daily-digest"),
};

export const LogViewerApp = ({ config }) => {
  const i18n = { ...defaultLogI18n, ...(config.i18n || {}) };
  const [rawItems, setRawItems] = useState([]);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState("");
  const [view, setView] = useState({
    type: "table",
    perPage: 25,
    fields: ["timestamp", "provider", "context", "method", "status", "url", "summary"],
    sort: {
      field: "timestamp",
      direction: "desc",
    },
  });

  const fields = useMemo(
    () => [
      {
        id: "timestamp",
        label: i18n.timestamp,
        enableGlobalSearch: true,
      },
      {
        id: "provider",
        label: i18n.provider,
        enableGlobalSearch: true,
      },
      {
        id: "context",
        label: i18n.context,
        enableGlobalSearch: true,
      },
      {
        id: "method",
        label: i18n.method,
        enableGlobalSearch: true,
      },
      {
        id: "status",
        label: i18n.status,
        enableGlobalSearch: true,
      },
      {
        id: "url",
        label: i18n.url,
        enableGlobalSearch: true,
        render: ({ item }) => {
          if (!item.url) {
            return "";
          }

          return (
            <a href={item.url} target="_blank" rel="noopener noreferrer">
              {item.url}
            </a>
          );
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

  const loadLogs = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage("");

    const response = await window.fetch(`${config.restRoot}/logs?limit=500`, {
      headers: {
        "X-WP-Nonce": config.restNonce,
      },
    });

    if (!response.ok) {
      setRawItems([]);
      setErrorMessage(i18n.loadError);
      setIsLoading(false);
      return;
    }

    const payload = await response.json();
    setRawItems(Array.isArray(payload.items) ? payload.items : []);
    setIsLoading(false);
  }, [config.restNonce, config.restRoot, i18n.loadError]);

  const actions = useMemo(
    () => [
      {
        id: "open-log-url",
        label: i18n.openUrl,
        isEligible: (item) => Boolean(item?.url),
        callback: ([item]) => {
          if (item?.url) {
            window.open(item.url, "_blank", "noopener,noreferrer");
          }
        },
      },
    ],
    [i18n.openUrl],
  );

  const { data: processedData, paginationInfo } = useMemo(
    () => filterSortAndPaginate(rawItems, view, fields),
    [fields, rawItems, view],
  );

  useEffect(() => {
    void loadLogs();
  }, [loadLogs]);

  return (
    <div>
      <div className="daily-digest-overview-controls">
        <Button variant="secondary" onClick={() => void loadLogs()}>
          {i18n.refresh}
        </Button>
      </div>

      {isLoading && (
        <p>
          <Spinner /> {i18n.loading}
        </p>
      )}

      {!isLoading && errorMessage && <p>{errorMessage}</p>}
      {!isLoading && !errorMessage && rawItems.length === 0 && <p>{i18n.noLogs}</p>}

      {!isLoading && !errorMessage && rawItems.length > 0 && (
        <DataViews
          data={processedData}
          fields={fields}
          view={view}
          onChangeView={setView}
          defaultLayouts={{ table: { layout: {} } }}
          actions={actions}
          paginationInfo={paginationInfo}
        />
      )}
    </div>
  );
};
