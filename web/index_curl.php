<?php
// 关闭脚本超时，避免大文件合并下载一半断开
set_time_limit(0);
// 隐藏非致命报错，保持输出流纯净
error_reporting(E_ERROR);

// 1. 基础配置
$github_repo = "sowahsun/My-Books"; 
$list_json_raw_url = "https://raw.githubusercontent.com/{$github_repo}/main/list.json";

$cache_file = __DIR__ . '/list.json.cache';
$cache_time = 600; 

// ==========================================
// 🚀 核心网络请求: cURL 兼容模式 (应对 allow_url_fopen 关闭)
// ==========================================
function safe_fetch_url($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64)");
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($data !== false && $code >= 200 && $code < 400) ? $data : false;
}

// ==========================================
// 代理节点远程配置与本地动态缓存
// ==========================================
$proxies_remote_url = "https://raw.githubusercontent.com/{$github_repo}/main/proxies.json";
$proxies_cache_file = __DIR__ . '/proxies.json.cache';

$proxies = [];
if (file_exists($proxies_cache_file) && (time() - filemtime($proxies_cache_file)) < $cache_time) {
    $proxies_data = file_get_contents($proxies_cache_file); 
    $proxies = json_decode($proxies_data, true);
} else {
    $proxies_data = safe_fetch_url($proxies_remote_url);
    if ($proxies_data !== false) {
        file_put_contents($proxies_cache_file, $proxies_data);
        $proxies = json_decode($proxies_data, true);
    } elseif (file_exists($proxies_cache_file)) {
        $proxies = json_decode(file_get_contents($proxies_cache_file), true);
    }
}

// 终极硬编码兜底
if (empty($proxies) || !is_array($proxies)) {
    $proxies = [
        "https://ghproxy.net/",
        "https://mirror.ghproxy.com/",
        "https://gh-proxy.com/",
        "https://cors.isteed.cc/",
        "https://v6.gh-proxy.org/",
        "" 
    ];
}

// ==========================================
// 中文 URL 标准化转码器
// ==========================================
function safe_encode_url($url) {
    $parsed = parse_url($url);
    if (!$parsed || !isset($parsed['path'])) return $url;
    
    $path_parts = explode('/', $parsed['path']);
    $encoded_parts = array_map(function($part) {
        return rawurlencode(urldecode($part));
    }, $path_parts);
    
    $encoded_path = implode('/', $encoded_parts);
    return $parsed['scheme'] . '://' . $parsed['host'] . $encoded_path;
}

// ==========================================
// 返回按速度排序的“可用节点梯队”
// ==========================================
function get_working_proxies_sorted($proxies, $test_url) {
    $mh = curl_multi_init();
    $handles = [];
    $safe_test_url = safe_encode_url($test_url);

    foreach ($proxies as $i => $proxy) {
        $ch = curl_init();
        $target = $proxy ? $proxy . $safe_test_url : $safe_test_url;
        curl_setopt($ch, CURLOPT_URL, $target);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Range: bytes=0-0',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = ['ch' => $ch, 'proxy' => $proxy];
    }
    
    $active = null;
    do { $mrc = curl_multi_exec($mh, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($mh) != -1) {
            do { $mrc = curl_multi_exec($mh, $active); } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        }
    }
    
    $working_nodes = [];
    foreach ($handles as $handle) {
        $ch = $handle['ch'];
        $proxy = $handle['proxy'];
        $info = curl_getinfo($ch);
        if ($info['http_code'] >= 200 && $info['http_code'] < 400) {
            $working_nodes[] = ['proxy' => $proxy, 'time' => $info['total_time']];
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    
    usort($working_nodes, function($a, $b) { return $a['time'] <=> $b['time']; });
    $sorted_proxies = array_column($working_nodes, 'proxy');
    if (empty($sorted_proxies)) $sorted_proxies = [""]; 
    
    return $sorted_proxies;
}

$requested_file = isset($_GET['file']) ? $_GET['file'] : '';
$requested_file = str_replace(['..', '\\', "\0"], '', $requested_file);

// ==========================================
// 缓存读取与数据清洗 (list.json)
// ==========================================
$json_data = false;
if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_time) {
    $json_data = file_get_contents($cache_file);
} else {
    $json_data = safe_fetch_url($list_json_raw_url);
    if ($json_data === false) {
        $cdn_proxies = ["https://ghproxy.net/", "https://mirror.ghproxy.com/", "https://gh-proxy.com/"];
        foreach ($cdn_proxies as $proxy) {
            $pull_url = $proxy . safe_encode_url($list_json_raw_url);
            $json_data = safe_fetch_url($pull_url);
            if ($json_data !== false) {
                break;
            }
        }
    }
    if ($json_data) {
        file_put_contents($cache_file, $json_data);
    } elseif (file_exists($cache_file)) {
        $json_data = file_get_contents($cache_file);
    }
}
$files = $json_data ? json_decode($json_data, true) : [];
if (!is_array($files)) $files = [];

$files = array_filter($files, function($file) {
    return strpos($file['path'], '.cache') !== 0;
});
$files = array_values($files);

// ==========================================
// 模式 A：现代动态网页前端 
// ==========================================
if (empty($requested_file)) {
    $json_string = json_encode($files, JSON_UNESCAPED_UNICODE);
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>中小学课本直通车 - 高清电子教科书免费下载_人教版统编版全学科PDF教材</title>
        <meta name="keywords" content="中小学课本,电子教科书,电子版教材,电子书下载,人教版电子课本,统编版教材,高清PDF教材,中小学电子书包,网课教材,高考资料,中考复习">
        <meta name="description" content="中小学课本直通车提供最新版全国中小学全学科电子教科书极速免费下载。涵盖人教版、统编版、苏教版、北师大版等全学段高清PDF教材，无广告、免限速，您的私人云端电子书包。">
        
        <style>
            :root { --primary: #2563eb; --primary-hover: #1d4ed8; --bg: #f8fafc; --card-bg: #ffffff; --text-main: #1e293b; --text-sub: #64748b; --border: #e2e8f0; }
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.5; margin: 0; padding: 0; background-color: var(--bg); color: var(--text-main); -webkit-tap-highlight-color: transparent; display: flex; flex-direction: column; min-height: 100vh; }
            .header { background: var(--card-bg); padding: 16px 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); position: sticky; top: 0; z-index: 10; display: flex; align-items: baseline; flex-wrap: wrap; gap: 10px; cursor: pointer; user-select: none; }
            .header h1 { margin: 0; font-size: 1.35rem; display: flex; align-items: center; gap: 8px; font-weight: 700; color: #0f172a; }
            .header .sub-title { font-size: 0.85rem; color: var(--text-sub); }
            
            .container { max-width: 960px; margin: 20px auto; padding: 0 16px; flex: 1; display: flex; flex-direction: column; }
            
            .search-box { margin-bottom: 16px; position: relative; }
            .search-box input { width: 100%; padding: 14px 36px 14px 44px; border: 1px solid var(--border); border-radius: 12px; font-size: 1rem; outline: none; box-sizing: border-box; transition: all 0.2s; background-color: var(--card-bg); box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
            .search-box input:focus { border-color: var(--primary); box-shadow: 0 0 0 4px rgba(37,99,235,0.1); }
            .search-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--text-sub); }
            
            .search-path { font-size: 0.8rem; color: #64748b; margin-top: 6px; display: block; word-break: break-all; line-height: 1.4; }
            .clear-btn { position: absolute; right: 14px; top: 50%; transform: translateY(-50%); background: #e2e8f0; border: none; border-radius: 50%; width: 24px; height: 24px; cursor: pointer; color: #64748b; font-size: 12px; line-height: 24px; text-align: center; padding: 0; display: none; }
            .clear-btn:hover { background: #cbd5e1; color: #334155; }
            mark { background: #fef08a; color: #1f2937; padding: 0 2px; border-radius: 2px; font-weight: 500; }
            .enter-dir { color: var(--primary); cursor: pointer; font-weight: 600; margin-left: 6px; white-space: nowrap; }
            .enter-dir:hover { text-decoration: underline; }
            .result-info { padding: 12px 20px; color: var(--text-sub); font-size: 0.85rem; border-bottom: 1px solid var(--border); background: #f8fafc; font-weight: 500; }
            
            .breadcrumb { background: var(--card-bg); padding: 12px 18px; border-radius: 10px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); display: flex; flex-wrap: wrap; gap: 6px; align-items: center; font-size: 0.9rem; overflow-x: auto; white-space: nowrap; border: 1px solid var(--border); }
            .breadcrumb span { cursor: pointer; color: var(--primary); font-weight: 500; }
            .breadcrumb span:hover { text-decoration: underline; }
            .breadcrumb .separator { color: var(--text-sub); margin: 0 4px; pointer-events: none; }
            .breadcrumb .current { color: var(--text-sub); cursor: default; pointer-events: none; }
            
            .file-list { background: var(--card-bg); border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); overflow: hidden; border: 1px solid var(--border); }
            
            .list-item { display: flex; align-items: center; padding: 16px 20px; border-bottom: 1px solid var(--border); transition: background-color 0.15s; }
            .list-item:last-child { border-bottom: none; }
            .list-item:hover { background-color: #f8fafc; }
            .list-item.folder { cursor: pointer; }
            
            /* SVG 文件夹样式 */
            .folder-icon-wrap { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; margin-right: 16px; flex-shrink: 0; transition: transform 0.15s; }
            .list-item:hover .folder-icon-wrap { transform: scale(1.05); }
            .folder-icon-wrap svg { width: 26px; height: 26px; }
            .folder-primary { background: #dbeafe; color: #2563eb; }
            .folder-success { background: #dcfce7; color: #16a34a; }
            .folder-warning { background: #fef3c7; color: #d97706; }
            .folder-danger { background: #fee2e2; color: #dc2626; }
            .folder-purple { background: #f3e8ff; color: #9333ea; }
            .folder-cyan { background: #cffafe; color: #0891b2; }
            .folder-gray { background: #f1f5f9; color: #475569; }
            
            .chevron { color: #cbd5e1; margin-left: 12px; flex-shrink: 0; transition: transform 0.2s, color 0.2s; }
            .list-item:hover .chevron { color: #94a3b8; transform: translateX(3px); }

            /* 图书卡片封面样式 */
            .icon { margin-right: 20px; display: flex; align-items: center; justify-content: center; width: 90px; flex-shrink: 0; }
            .cover-img { width: 90px; height: 126px; object-fit: cover; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.12); display: block; border: 1px solid var(--border); background: #fff; }
            .default-pdf { width: 50px; height: 50px; }
            
            .info { flex-grow: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; min-height: 126px; padding: 4px 0; }
            .info.folder-info { min-height: auto; } 
            
            .name { font-size: 1.05rem; font-weight: 600; line-height: 1.4; word-break: break-all; color: var(--text-main); margin-bottom: 6px; }
            
            /* 精美元数据胶囊标签（卡片化元数据） */
            .meta-tags { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 8px; }
            .tag { font-size: 0.75rem; padding: 2px 8px; border-radius: 6px; font-weight: 500; display: inline-flex; align-items: center; gap: 3px; }
            .tag-stage { background: #e0e7ff; color: #3730a3; }
            .tag-subject { background: #e0f2fe; color: #0369a1; }
            .tag-pub { background: #f1f5f9; color: #475569; }
            .tag-grade { background: #fef3c7; color: #b45309; }

            .meta { display: flex; align-items: center; gap: 12px; margin-top: auto; flex-wrap: wrap; }
            .chunked-icon { font-size: 1rem; cursor: pointer; opacity: 0.8; transition: opacity 0.2s; flex-shrink: 0; }
            .chunked-icon:hover { opacity: 1; }
            
            .download-btn { background-color: var(--primary); color: white; text-decoration: none; padding: 10px 20px; font-size: 0.95rem; border-radius: 10px; font-weight: 500; margin-left: 16px; transition: all 0.2s; white-space: nowrap; display: flex; align-items: center; gap: 6px; flex-shrink: 0; align-self: center; box-shadow: 0 1px 2px rgba(37,99,235,0.2); }
            .download-btn:hover { background-color: var(--primary-hover); box-shadow: 0 4px 12px rgba(37,99,235,0.25); transform: translateY(-1px); }
            .download-btn:active { transform: scale(0.97); }
            
            .empty-state { padding: 48px 20px; text-align: center; color: var(--text-sub); font-size: 0.95rem; }
            
            .disclaimer-footer { margin: 16px 0 8px 0; background: var(--card-bg); border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.04); border: 1px solid var(--border); padding: 8px 12px; margin-top: auto; }
            .disclaimer-footer .disclaimer-text { font-size: 0.8rem; color: var(--text-sub); }
            .disclaimer-footer .disclaimer-text a { color: var(--primary); text-decoration: none; font-weight: 500; }
            .disclaimer-footer .disclaimer-text a:hover { text-decoration: underline; }
            
            @media (max-width: 600px) {
                .header h1 { font-size: 1.15rem; }
                .search-box input { font-size: 0.88rem; padding: 12px 30px 12px 38px; }
                
                .list-item { padding: 16px; align-items: flex-start; position: relative; } 
                
                .folder-icon-wrap { width: 42px; height: 42px; margin-right: 12px; }
                .folder-icon-wrap svg { width: 22px; height: 22px; }
                
                .icon { width: 72px; margin-right: 14px; margin-top: 0; }
                .cover-img { width: 72px; height: 100px; border-radius: 6px; }
                .default-pdf { width: 40px; height: 40px; }
                
                .info { min-height: 100px; height: auto; justify-content: flex-start; padding: 0; padding-bottom: 30px; width: 100%; } 
                .list-item.folder .info { padding-bottom: 0; min-height: auto; } 
                
                .name { font-size: 0.95rem; margin-bottom: 6px; line-height: 1.35; }
                .search-path { font-size: 0.75rem; margin-top: 2px; margin-bottom: 4px; }
                
                .meta-tags { margin-bottom: 4px; gap: 5px; }
                .tag { font-size: 0.7rem; padding: 2px 6px; }
                .meta { margin-top: 6px; gap: 8px; }
                
                .download-btn { 
                    position: absolute; 
                    right: 16px; 
                    bottom: 16px; 
                    padding: 6px 14px; 
                    font-size: 0.85rem; 
                    margin: 0; 
                    border-radius: 8px;
                    z-index: 2;
                } 
                .chevron { display: none; }
            }
            .header { flex-wrap: nowrap; }
            .header .sub-title { font-size: 0.75rem; }
            .header h1 { font-size: 1.2rem; white-space: nowrap; }
            .header .sub-title { white-space: nowrap; }
            @media (max-width: 600px) {
                .header h1 { font-size: 1.0rem; }
            }
            @media (min-width: 601px) {
                .header h1 { font-size: 1.4rem; }
                .header .sub-title { font-size: 0.85rem; }
                .header { padding: 16px 32px; gap: 12px; }
            }
        </style>
    </head>
    <body>
        <div class="header" onclick="renderView('')">
            <h1>📚 中小学课本直通车</h1>
            <span class="sub-title">全学科·高清电子教科书镜像库</span>
        </div>
        
        <div class="container">
            <div class="search-box">
                <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="searchInput" placeholder="搜索教材/出版社 (空格分词，如: 小学 语文)" autocomplete="off">
                <button class="clear-btn" id="clearSearch" title="清空">✕</button>
            </div>
            
            <div class="breadcrumb" id="breadcrumb"></div>
            <div class="file-list" id="fileList"></div>
            
            <div class="disclaimer-footer">
                <div class="disclaimer-text">
                    📖 资源索引自 <a href="https://github.com/TapXWorld/ChinaTextbook" target="_blank" rel="noopener">TapXWorld/ChinaTextbook</a>，本站仅提供镜像加速，版权归原出版社所有。
                </div>
            </div>
        </div>
        
        <script>
            const allFiles = <?php echo $json_string; ?>;
            let currentPath = '';
            let isSearching = false;
            const clearSearchBtn = document.getElementById('clearSearch');
            const GH_REPO = "<?php echo $github_repo; ?>";

            function esc(text) {
                const d = document.createElement('div');
                d.innerText = text;
                return d.innerHTML;
            }

            function highlight(text, terms) {
                let h = esc(text);
                terms.forEach(t => {
                    if (!t) return;
                    const s = t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    h = h.replace(new RegExp('(' + s + ')', 'gi'), '<mark>$1</mark>');
                });
                return h;
            }

            function pySanitize(name) {
                let s = name.replace(/[<>:"\/\\|?*\x00-\x1f]/g, '_');
                s = s.replace(/^[ .]+|[ .]+$/g, '');
                return s;
            }

            function getCoverUrl(filePath) {
                const parts = filePath.split('/');
                const sanitizedParts = parts.map(p => pySanitize(p)).filter(p => p !== '');
                if (sanitizedParts.length === 0) sanitizedParts.push('unknown');
                
                const relPath = sanitizedParts.map(p => encodeURIComponent(p)).join('/');
                const jpgPath = relPath.replace(/\.[^/.]+$/, "") + ".jpg";
                
                const d = new Date();
                const cacheBuster = d.getFullYear() + "-" + d.getMonth() + "-" + d.getDate() + "-" + d.getHours();
                
                return `https://cdn.jsdelivr.net/gh/${GH_REPO}@main/covers/${jpgPath}?v=${cacheBuster}`;
            }

            const defaultSvg = `<svg class="default-pdf" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#e11d48" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><text x="12" y="17" font-size="6.5" font-weight="bold" stroke="none" fill="#e11d48" font-family="Arial, sans-serif" text-anchor="middle">PDF</text></svg>`;

            window.handleImgError = function(imgElement) {
                imgElement.outerHTML = defaultSvg;
            };

            function parseBookMeta(filePath, fileName) {
                const parts = filePath.split('/');
                let stage = parts[0] || '';
                let subject = parts.length > 1 ? parts[1] : '';
                let publisher = '';
                let grade = '';

                if (parts.length >= 4) {
                    publisher = parts[2];
                    grade = parts[3];
                } else if (parts.length === 3) {
                    grade = parts[2];
                }
                
                if (fileName) {
                    if (grade === fileName || grade.includes('.pdf') || grade.includes('_merge_folder')) grade = '';
                    if (publisher === fileName || publisher.includes('.pdf') || publisher.includes('_merge_folder')) publisher = '';
                }

                return { stage, subject, publisher, grade };
            }

            document.getElementById('searchInput').addEventListener('input', (e) => {
                const keyword = e.target.value.trim().toLowerCase();
                clearSearchBtn.style.display = keyword.length > 0 ? 'block' : 'none';
                if (keyword === '') {
                    isSearching = false;
                    document.getElementById('breadcrumb').style.display = 'flex';
                    renderFiles(); 
                } else {
                    isSearching = true;
                    renderSearchResults(keyword);
                }
            });

            clearSearchBtn.addEventListener('click', () => {
                document.getElementById('searchInput').value = '';
                clearSearchBtn.style.display = 'none';
                isSearching = false;
                document.getElementById('breadcrumb').style.display = 'flex';
                renderFiles();
            });

            function enterDir(dir) {
                document.getElementById('searchInput').value = '';
                clearSearchBtn.style.display = 'none';
                isSearching = false;
                renderView(dir, true);
            }

            function exitSearch() {
                document.getElementById('searchInput').value = '';
                clearSearchBtn.style.display = 'none';
                isSearching = false;
                renderView('', true);
            }

            function getFolderClass(name) {
                const n = name.toLowerCase();
                
                if (n.includes('小学')) return 'folder-primary';
                if (n.includes('初中')) return 'folder-success';
                if (n.includes('高中')) return 'folder-danger';
                if (n.includes('大学')) return 'folder-purple';
                if (n.includes('五·四') || n.includes('五四')) return 'folder-cyan';
                
                if (n.includes('数学') || n.includes('习题') || n.includes('概率') || n.includes('高数') || n.includes('线性')) return 'folder-warning';
                if (n.includes('语文') || n.includes('读本') || n.includes('历史')) return 'folder-danger';
                if (n.includes('英语') || n.includes('外语') || n.includes('俄语') || n.includes('日语')) return 'folder-cyan';
                if (n.includes('物理') || n.includes('化学') || n.includes('科学') || n.includes('生物')) return 'folder-success';
                if (n.includes('地理') || n.includes('道德') || n.includes('法治') || n.includes('政治')) return 'folder-primary';
                if (n.includes('音乐') || n.includes('美术') || n.includes('艺术') || n.includes('体育')) return 'folder-purple';
                
                if (n.includes('年级') || n.includes('上册') || n.includes('下册') || n.includes('全一册')) return 'folder-warning';
                if (n.includes('版') || n.includes('出版社') || n.includes('社')) return 'folder-cyan';

                let hash = 0;
                for (let i = 0; i < name.length; i++) {
                    hash = name.charCodeAt(i) + ((hash << 5) - hash);
                }
                const palettes = ['folder-primary', 'folder-success', 'folder-warning', 'folder-danger', 'folder-purple', 'folder-cyan'];
                return palettes[Math.abs(hash) % palettes.length];
            }

            function renderSearchResults(keywordStr) {
                const listWrap = document.getElementById('fileList');
                listWrap.innerHTML = '';
                
                const keywords = keywordStr.split(/\s+/).filter(k => k.length > 0);
                const results = allFiles.filter(item => {
                    const searchTarget = (item.path + ' ' + item.name).toLowerCase();
                    return keywords.every(kw => searchTarget.includes(kw));
                });

                results.sort((a, b) => {
                    const aName = a.name.toLowerCase();
                    const bName = b.name.toLowerCase();
                    const aHit = keywords.some(t => aName.includes(t));
                    const bHit = keywords.some(t => bName.includes(t));
                    if (aHit && !bHit) return -1;
                    if (!aHit && bHit) return 1;
                    return a.name.localeCompare(b.name, 'zh-CN');
                });

                const bcWrap = document.getElementById('breadcrumb');
                bcWrap.innerHTML = '<span onclick="exitSearch()">🏠 首页</span><span class="separator">›</span><span class="current">搜索: ' + esc(keywordStr) + '</span>';
                bcWrap.style.display = 'flex';

                if (results.length === 0) {
                    listWrap.innerHTML = '<div class="empty-state">没有找到匹配的教材 🥲</div>';
                    return;
                }

                const infoDiv = document.createElement('div');
                infoDiv.className = 'result-info';
                infoDiv.innerHTML = '找到 <strong>' + results.length + '</strong> 个结果';
                listWrap.appendChild(infoDiv);

                results.forEach(file => {
                    const div = document.createElement('div');
                    div.className = 'list-item';
                    
                    const badgeHtml = file.is_chunked ? `<span class="chunked-icon" onclick="event.stopPropagation(); alert('此文件由多个分片自动合并，下载不受影响。')" title="点击了解详情">🧩</span>` : '';
                    const encodedPath = encodeURIComponent(file.path);
                    
                    let iconHtml = defaultSvg;
                    
                    if (file.name.toLowerCase().endsWith('.pdf')) {
                        const coverUrl = getCoverUrl(file.path);
                        iconHtml = `<a href="${coverUrl}" target="_blank" title="点击预览高清封面" style="display: block; cursor: zoom-in; position: relative; z-index: 2;"><img src="${coverUrl}" class="cover-img" loading="lazy" onerror="handleImgError(this)" alt="cover"/></a>`;
                    }
                    
                    const meta = parseBookMeta(file.path, file.name);
                    let tagsHtml = `
                        <div class="meta-tags">
                            ${meta.stage ? `<span class="tag tag-stage">🎓 ${esc(meta.stage)}</span>` : ''}
                            ${meta.subject ? `<span class="tag tag-subject">📖 ${esc(meta.subject)}</span>` : ''}
                            ${meta.publisher ? `<span class="tag tag-pub">🏢 ${esc(meta.publisher)}</span>` : ''}
                            ${meta.grade ? `<span class="tag tag-grade">📌 ${esc(meta.grade)}</span>` : ''}
                        </div>
                    `;

                    const lastSlash = file.path.lastIndexOf('/');
                    const dirPath = lastSlash > 0 ? file.path.substring(0, lastSlash + 1) : '';
                    const parentDir = dirPath.replace(/\/$/, '') || '根目录';

                    div.innerHTML = `
                        <div class="icon">${iconHtml}</div>
                        <div class="info">
                            <div class="name">${highlight(file.name, keywords)}</div>
                            ${tagsHtml}
                            <span class="search-path">📁 所属: ${highlight(parentDir, keywords)} <span class="enter-dir" onclick="event.stopPropagation();enterDir('${esc(dirPath)}')">[进入目录]</span></span>
                            <div class="meta">
                                ${badgeHtml}
                            </div>
                        </div>
                        <a href="?file=${encodedPath}" class="download-btn">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg> ${file.size || '下载'}
                        </a>
                    `;
                    listWrap.appendChild(div);
                });
            }

            function renderView(path, pushHistory = true) { 
                currentPath = path; 
                document.getElementById('searchInput').value = ''; 
                isSearching = false;
                clearSearchBtn.style.display = 'none';
                document.getElementById('breadcrumb').style.display = 'flex';
                
                if (pushHistory) {
                    const url = new URL(window.location);
                    if (path === '') {
                        url.searchParams.delete('dir');
                    } else {
                        url.searchParams.set('dir', path);
                    }
                    window.history.pushState({ path: path }, '', url);
                }
                renderBreadcrumb(); 
                renderFiles(); 
            }

            window.addEventListener('popstate', (event) => {
                const urlParams = new URLSearchParams(window.location.search);
                currentPath = urlParams.get('dir') || '';
                document.getElementById('searchInput').value = '';
                isSearching = false;
                clearSearchBtn.style.display = 'none';
                document.getElementById('breadcrumb').style.display = 'flex';
                renderBreadcrumb();
                renderFiles();
            });

            function renderBreadcrumb() {
                const bcWrap = document.getElementById('breadcrumb');
                bcWrap.innerHTML = '';
                const homeSpan = document.createElement('span');
                homeSpan.innerText = '🏠 首页';
                homeSpan.onclick = () => renderView('');
                bcWrap.appendChild(homeSpan);
                if (currentPath === '') { homeSpan.className = 'current'; return; }
                const parts = currentPath.split('/').filter(p => p !== '');
                let accumPath = '';
                parts.forEach((part, index) => {
                    const sep = document.createElement('span'); sep.className = 'separator'; sep.innerText = '›'; bcWrap.appendChild(sep);
                    accumPath += part + '/';
                    const partSpan = document.createElement('span'); partSpan.innerText = part;
                    if (index === parts.length - 1) {
                        partSpan.className = 'current';
                    } else {
                        const targetPath = accumPath;
                        partSpan.onclick = () => renderView(targetPath);
                    }
                    bcWrap.appendChild(partSpan);
                });
            }

            function renderFiles() {
                if (isSearching) return; 

                const listWrap = document.getElementById('fileList'); listWrap.innerHTML = '';
                if (allFiles.length === 0) { listWrap.innerHTML = '<div class="empty-state">暂无数据，请检查仓库索引是否生成。</div>'; return; }
                const folders = new Set(); const files = [];
                allFiles.forEach(item => {
                    if (item.path.startsWith(currentPath)) {
                        const relativePath = item.path.substring(currentPath.length);
                        const parts = relativePath.split('/');
                        if (parts.length === 1) { files.push(item); } else { folders.add(parts[0]); }
                    }
                });
                if (folders.size === 0 && files.length === 0) { listWrap.innerHTML = '<div class="empty-state">当前目录为空</div>'; return; }
                Array.from(folders).sort().forEach(folder => {
                    const div = document.createElement('div'); div.className = 'list-item folder'; div.onclick = () => renderView(currentPath + folder + '/');
                    const folderClass = getFolderClass(folder);
                    div.innerHTML = `
                        <div class="folder-icon-wrap ${folderClass}">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
                            </svg>
                        </div>
                        <div class="info folder-info">
                            <div class="name">${folder}</div>
                        </div>
                        <svg class="chevron" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="9 18 15 12 9 6"></polyline>
                        </svg>
                    `;
                    listWrap.appendChild(div);
                });
                files.forEach(file => {
                    const div = document.createElement('div'); div.className = 'list-item';
                    const badgeHtml = file.is_chunked ? `<span class="chunked-icon" onclick="event.stopPropagation(); alert('此文件由多个分片自动合并，下载不受影响。')" title="点击了解详情">🧩</span>` : '';
                    const encodedPath = encodeURIComponent(file.path);
                    
                    let iconHtml = defaultSvg;

                    if (file.name.toLowerCase().endsWith('.pdf')) {
                        const coverUrl = getCoverUrl(file.path);
                        iconHtml = `<a href="${coverUrl}" target="_blank" title="点击预览高清封面" style="display: block; cursor: zoom-in; position: relative; z-index: 2;"><img src="${coverUrl}" class="cover-img" loading="lazy" onerror="handleImgError(this)" alt="cover"/></a>`;
                    }
                    
                    const meta = parseBookMeta(file.path, file.name);
                    let tagsHtml = `
                        <div class="meta-tags">
                            ${meta.stage ? `<span class="tag tag-stage">🎓 ${esc(meta.stage)}</span>` : ''}
                            ${meta.subject ? `<span class="tag tag-subject">📖 ${esc(meta.subject)}</span>` : ''}
                            ${meta.publisher ? `<span class="tag tag-pub">🏢 ${esc(meta.publisher)}</span>` : ''}
                            ${meta.grade ? `<span class="tag tag-grade">📌 ${esc(meta.grade)}</span>` : ''}
                        </div>
                    `;

                    div.innerHTML = `
                        <div class="icon">${iconHtml}</div>
                        <div class="info">
                            <div class="name">${file.name}</div>
                            ${tagsHtml}
                            <div class="meta">
                                ${badgeHtml}
                            </div>
                        </div>
                        <a href="?file=${encodedPath}" class="download-btn">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg> ${file.size || '下载'}
                        </a>
                    `;
                    listWrap.appendChild(div);
                });
            }
            
            const urlParams = new URLSearchParams(window.location.search);
            const initDir = urlParams.get('dir') || '';
            renderView(initDir, false);
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ==========================================
// 模式 B：抗风控无缝接力代理下载 (cURL 流式全自动透传版)
// ==========================================
$target_file_data = null;
foreach ($files as $file) {
    if ($file['path'] === $requested_file) {
        $target_file_data = $file;
        break;
    }
}

if (!$target_file_data) {
    header("HTTP/1.0 404 Not Found");
    die("未找到文件: " . htmlspecialchars($requested_file));
}

$working_proxies = get_working_proxies_sorted($proxies, $list_json_raw_url);
$filename = $target_file_data['name'];
$headers_sent = false;

// 清除可能存在的输出缓冲，确保流式透传数据直接推向用户，不被服务器憋住
while (ob_get_level()) { @ob_end_clean(); }

function send_download_headers($filename) {
    global $headers_sent;
    if (!$headers_sent) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        $headers_sent = true;
    }
}

// 核心流式转发引擎 (零磁盘占用)
function stream_file_with_curl($url, $filename) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64)");
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); 
    
    $is_fake = false;
    $headers_checked = false;

    // 分析 CDN 返回的头信息，看是不是假文件
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$is_fake) {
        if (stripos($header, 'Content-Type: text/html') !== false) {
            $is_fake = true;
        }
        return strlen($header);
    });

    // 数据一块一块地透传给浏览器
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use (&$is_fake, &$headers_checked, $filename) {
        if ($is_fake) {
            return 0; // 发现是假的 HTML，直接中止
        }
        
        if (!$headers_checked) {
            $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if ($code >= 400) {
                return 0; 
            }
            send_download_headers($filename); 
            $headers_checked = true;
        }
        
        echo $data;
        @flush(); 
        return strlen($data);
    });
    
    $success = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($success !== false && !$is_fake && $code >= 200 && $code < 400);
}

// 执行分发与容灾重试逻辑
if ($target_file_data['is_chunked'] && isset($target_file_data['chunks'])) {
    foreach ($target_file_data['chunks'] as $chunk) {
        $chunk_raw_url = safe_encode_url($chunk['url']);
        $chunk_success = false;
        
        foreach ($working_proxies as $proxy) {
            $chunk_download_url = $proxy ? $proxy . $chunk_raw_url : $chunk_raw_url;
            if (stream_file_with_curl($chunk_download_url, $filename)) {
                $chunk_success = true;
                break;
            }
        }
        
        if (!$chunk_success) {
            if (!$headers_sent) {
                header('Content-Type: text/plain; charset=utf-8');
                die("\n❌ 下载失败：所有加速节点均被风控或无法连接（分片 " . htmlspecialchars($chunk['index']) . "）。请稍后再试！");
            } else {
                die(); 
            }
        }
    }
} else {
    $file_raw_url = safe_encode_url($target_file_data['url']);
    $file_success = false;
    
    foreach ($working_proxies as $proxy) {
        $file_download_url = $proxy ? $proxy . $file_raw_url : $file_raw_url;
        if (stream_file_with_curl($file_download_url, $filename)) {
            $file_success = true;
            break;
        }
    }
    
    if (!$file_success) {
        header('Content-Type: text/plain; charset=utf-8');
        die("\n❌ 下载失败：所有加速节点均被风控或无法获取该文件。请稍后再试！");
    }
}
exit;
?>
