import 'dart:async';
import 'dart:collection';
import 'dart:convert';

/// One instance per signed-in account and screen. No HTTP package is required.
class SearchRequest {
  final String text;
  final Map<String, Object?> filters;
  final int page;
  final int perPage;
  SearchRequest(
      this.text, Map<String, Object?> filters, this.page, this.perPage)
      : filters = Map.unmodifiable(filters);
  Map<String, Object?> get queryParameters => {
        ...filters,
        'q': text,
        'page': page,
        'per_page': perPage,
      };
  String get key {
    final ordered = SplayTreeMap<String, Object?>.from(queryParameters);
    return jsonEncode(ordered);
  }
}

class SearchPage<T> {
  final List<T> items;
  final int page;
  final int total;
  final int lastPage;
  SearchPage(List<T> items, this.page, this.total, this.lastPage)
      : items = List.unmodifiable(items);
}

class SearchState<T> {
  final List<T> items;
  final bool loading;
  final bool preview;
  final bool fromCache;
  final Object? error;
  final int? total;
  SearchState(List<T> items,
      {this.loading = false,
      this.preview = false,
      this.fromCache = false,
      this.error,
      this.total})
      : items = List.unmodifiable(items);
}

class _CachedPage<T> {
  final SearchPage<T> page;
  final DateTime expiresAt;
  _CachedPage(this.page, this.expiresAt);
}

class DebouncedSearch<T> {
  final Future<SearchPage<T>> Function(SearchRequest request) fetch;
  final bool Function(T item, String text) matches;
  final String Function(T item) keyOf;
  final void Function(SearchState<T> state) onState;
  final Duration delay;
  final Duration cacheTtl;
  final int perPage;
  final int maxCachedPages;
  final _cache = LinkedHashMap<String, _CachedPage<T>>();
  Timer? _timer;
  int _version = 0;
  bool _disposed = false;
  bool _loadingMore = false;
  String? _activeKey;
  String _text = '';
  String _filterKey = '';
  Map<String, Object?> _filters = {};
  List<T> _loaded = [];
  SearchPage<T>? _confirmed;

  DebouncedSearch({
    required this.fetch,
    required this.matches,
    required this.keyOf,
    required this.onState,
    this.delay = const Duration(milliseconds: 350),
    this.cacheTtl = const Duration(seconds: 60),
    this.perPage = 20,
    this.maxCachedPages = 30,
  });

  void search(String text,
      {Map<String, Object?> filters = const {}, bool force = false}) {
    if (_disposed) return;
    final request = SearchRequest(text.trim(), filters, 1, perPage);
    // A pending or scheduled identical search must not create duplicate calls.
    if (!force && _activeKey == request.key) return;
    _timer?.cancel();
    final version = ++_version; // Invalidates older replies immediately.
    final filterKey = jsonEncode(SplayTreeMap<String, Object?>.from(filters));
    if (_filterKey != filterKey) _loaded = [];
    _filterKey = filterKey;
    _text = request.text;
    _filters = request.filters;
    _confirmed = null;
    _loadingMore = false;
    _activeKey = request.key;
    final cached = force ? null : _readCache(request.key);
    if (cached != null) {
      _loaded = cached.items.toList();
      _confirmed = cached;
      _activeKey = null;
      _emit(SearchState(_loaded, fromCache: true, total: cached.total));
      return;
    }
    final preview = _loaded.where((item) => matches(item, _text)).toList();
    _emit(SearchState(preview, preview: true, loading: true));
    _timer = Timer(delay, () => _loadFirst(request, version, preview));
  }

  Future<void> _loadFirst(
      SearchRequest request, int version, List<T> preview) async {
    try {
      final page = await fetch(request);
      if (_disposed || version != _version) return;
      _remember(request.key, page);
      _loaded = page.items.toList();
      _confirmed = page;
      _activeKey = null;
      _emit(SearchState(_loaded, total: page.total));
    } catch (error) {
      if (_disposed || version != _version) return;
      _activeKey = null;
      _emit(SearchState(preview, preview: true, error: error));
    }
  }

  Future<void> loadNextPage() async {
    final confirmed = _confirmed;
    if (_disposed ||
        _loadingMore ||
        _activeKey != null ||
        confirmed == null ||
        confirmed.page >= confirmed.lastPage) return;
    final version = _version;
    _loadingMore = true;
    _emit(SearchState(_loaded, loading: true, total: confirmed.total));
    final request = SearchRequest(_text, _filters, confirmed.page + 1, perPage);
    try {
      final cached = _readCache(request.key);
      final page = cached ?? await fetch(request);
      if (_disposed || version != _version) return;
      _remember(request.key, page);
      final unique = {for (final item in _loaded) keyOf(item): item};
      for (final item in page.items) {
        unique[keyOf(item)] = item;
      }
      _loaded = unique.values.toList();
      _confirmed = page;
      _emit(SearchState(_loaded, fromCache: cached != null, total: page.total));
    } catch (error) {
      if (_disposed || version != _version) return;
      _emit(SearchState(_loaded, error: error, total: confirmed.total));
    } finally {
      if (version == _version) _loadingMore = false;
    }
  }

  void refresh() {
    _cache.clear();
    search(_text, filters: _filters, force: true);
  }

  SearchPage<T>? _readCache(String key) {
    final cached = _cache.remove(key);
    if (cached == null || !DateTime.now().isBefore(cached.expiresAt))
      return null;
    _cache[key] = cached;
    return cached.page;
  }

  void _remember(String key, SearchPage<T> page) {
    if (maxCachedPages <= 0) return;
    _cache.remove(key);
    _cache[key] = _CachedPage(page, DateTime.now().add(cacheTtl));
    while (_cache.length > maxCachedPages) {
      _cache.remove(_cache.keys.first);
    }
  }

  void _emit(SearchState<T> state) {
    if (!_disposed) onState(state);
  }

  void dispose() {
    _disposed = true;
    _version++;
    _timer?.cancel();
    _cache.clear();
    _loaded = [];
  }
}
