<template>
  <div class="tutorials-container">
    <div class="view-header">
      <div>
        <h1 class="view-title" style="display:flex; align-items:center; gap:10px;">
          <i class="fa-solid fa-film" style="color:#a855f7;"></i> Tutoriales en Video
        </h1>
        <p class="view-subtitle">Aprende a dominar todas las funciones de Ábaco y escalar tus finanzas paso a paso</p>
      </div>
    </div>

    <!-- Filtro de Categorías -->
    <div class="tutorial-filters" style="display:flex; gap:10px; margin-bottom:20px; overflow-x:auto; padding-bottom:6px;">
      <button 
        v-for="cat in categories" 
        :key="cat" 
        class="filter-pill-btn" 
        :class="{ active: selectedCategory === cat }"
        @click="selectedCategory = cat"
      >
        {{ cat }}
      </button>
    </div>

    <!-- Grid de Videos -->
    <div class="tutorials-grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap:20px;">
      <div 
        v-for="video in filteredVideos" 
        :key="video.id" 
        class="glass-card tutorial-card" 
        @click="playVideo(video)"
      >
        <div class="video-thumbnail-container" style="position:relative; width:100%; height:160px; border-radius:12px; overflow:hidden; background:#1e293b; display:flex; align-items:center; justify-content:center;">
          <img v-if="video.thumbnail" :src="video.thumbnail" :alt="video.title" style="width:100%; height:100%; object-fit:cover;" />
          <div v-else style="display:flex; flex-direction:column; align-items:center; gap:8px; color:var(--text-muted);">
            <i class="fa-solid fa-circle-play" style="font-size:42px; color:#a855f7;"></i>
          </div>
          <div class="play-overlay" style="position:absolute; inset:0; background:rgba(0,0,0,0.35); display:flex; align-items:center; justify-content:center; opacity:0.9; transition:all 0.2s ease;">
            <div style="width:50px; height:50px; border-radius:50%; background:rgba(168,85,247,0.9); display:flex; align-items:center; justify-content:center; color:#fff; font-size:18px; box-shadow:0 4px 15px rgba(168,85,247,0.5);">
              <i class="fa-solid fa-play" style="margin-left:3px;"></i>
            </div>
          </div>
          <span class="video-duration" style="position:absolute; bottom:8px; right:8px; background:rgba(0,0,0,0.75); color:#fff; font-size:11px; font-weight:700; padding:2px 6px; border-radius:4px;">
            {{ video.duration || 'Video' }}
          </span>
        </div>

        <div class="tutorial-card-body" style="padding-top:14px;">
          <span class="category-badge" style="font-size:10px; font-weight:700; text-transform:uppercase; color:#38bdf8; letter-spacing:0.5px;">
            {{ video.category }}
          </span>
          <h3 style="font-size:15px; font-weight:700; color:var(--text-primary); margin:4px 0 6px 0; line-height:1.3;">
            {{ video.title }}
          </h3>
          <p style="font-size:12.5px; color:var(--text-secondary); line-height:1.4; margin:0; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;">
            {{ video.description }}
          </p>
        </div>
      </div>
    </div>

    <!-- Modal Reproductor de Video -->
    <div v-if="activeVideo" class="modal-overlay active" @click.self="activeVideo = null" style="position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(6px); display:flex; align-items:center; justify-content:center; z-index:9999; padding:16px;">
      <div class="glass-card modal-content" style="max-width:760px; width:100%; padding:20px; border-radius:16px; background:var(--card-bg); border:1px solid var(--card-border); box-shadow:0 20px 50px rgba(0,0,0,0.5);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
          <h3 style="font-size:16px; font-weight:700; color:var(--text-primary); margin:0; line-height:1.3;">{{ activeVideo.title }}</h3>
          <button class="btn-close" @click="activeVideo = null" style="background:none; border:none; color:var(--text-secondary); font-size:22px; cursor:pointer; padding:4px 8px;">&times;</button>
        </div>
        
        <div class="video-embed-wrapper" style="position:relative; width:100%; aspect-ratio:16/9; border-radius:12px; overflow:hidden; background:#000; display:flex; align-items:center; justify-content:center;">
          <iframe 
            v-if="activeVideo.embedUrl" 
            :src="activeVideo.embedUrl" 
            style="width:100%; height:100%; border:0;" 
            allowfullscreen 
            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
          ></iframe>
          <video v-else-if="activeVideo.url" controls autoplay style="width:100%; height:100%;">
            <source :src="activeVideo.url" type="video/mp4" />
            Tu navegador no soporta reproducción de video.
          </video>
        </div>

        <div style="margin-top:14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
          <span style="font-size:11px; font-weight:700; color:#38bdf8; text-transform:uppercase;">
            Categoría: {{ activeVideo.category }}
          </span>
          <p style="font-size:13px; color:var(--text-secondary); margin:0; line-height:1.4; flex:1 1 100%;">
            {{ activeVideo.description }}
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, computed, onMounted } from 'vue'

export default {
  name: 'TutorialsView',
  setup() {
    const categories = ['Todos', 'General', 'Modo Negocio', 'IA y Voz', 'Préstamos y Cobros']
    const selectedCategory = ref('Todos')
    const activeVideo = ref(null)

    const defaultVideos = [
      {
        id: 1,
        title: '🎙️ Cómo registrar gastos con el Dictado por Voz e IA',
        category: 'IA y Voz',
        duration: '2:15',
        description: 'Aprende a usar la voz para decir cosas como "Me gasté 50 mil en cine" y dejar que la IA categorice todo automáticamente.',
        url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        embedUrl: 'https://www.youtube.com/embed/dQw4w9WgXcQ'
      },
      {
        id: 2,
        title: '🏬 Guía completa del Modo Negocio (Caja chica y ventas)',
        category: 'Modo Negocio',
        duration: '3:40',
        description: 'Aprende a separar tus cuentas de empresa, medir tus ganancias netas y llevar el control diario de tu emprendimiento.',
        url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        embedUrl: 'https://www.youtube.com/embed/dQw4w9WgXcQ'
      },
      {
        id: 3,
        title: '📊 Cómo gestionar Préstamos, Deudores y Cobros a Clientes',
        category: 'Préstamos y Cobros',
        duration: '4:05',
        description: 'Registra tus clientes, préstamos por cobrar, fechas de pago y mantén tu cartera morosa bajo control.',
        url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        embedUrl: 'https://www.youtube.com/embed/dQw4w9WgXcQ'
      }
    ]

    const videos = ref([])

    const loadVideos = () => {
      const stored = localStorage.getItem('abaco_tutorials')
      if (stored) {
        try {
          videos.value = JSON.parse(stored)
        } catch (e) {
          videos.value = defaultVideos
        }
      } else {
        videos.value = defaultVideos
      }
    }

    const filteredVideos = computed(() => {
      if (selectedCategory.value === 'Todos') return videos.value
      return videos.value.filter(v => v.category === selectedCategory.value)
    })

    const playVideo = (video) => {
      let embed = video.embedUrl
      if (!embed && video.url) {
        if (video.url.includes('youtube.com/watch?v=')) {
          const id = video.url.split('v=')[1]?.split('&')[0]
          embed = `https://www.youtube.com/embed/${id}`
        } else if (video.url.includes('youtu.be/')) {
          const id = video.url.split('youtu.be/')[1]?.split('?')[0]
          embed = `https://www.youtube.com/embed/${id}`
        }
      }
      activeVideo.value = { ...video, embedUrl: embed }
    }

    onMounted(() => {
      loadVideos()
    })

    return {
      categories,
      selectedCategory,
      filteredVideos,
      activeVideo,
      playVideo
    }
  }
}
</script>

<style scoped>
.filter-pill-btn {
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid var(--card-border);
  color: var(--text-secondary);
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.2s ease;
}
.filter-pill-btn.active, .filter-pill-btn:hover {
  background: rgba(168, 85, 247, 0.18);
  border-color: rgba(168, 85, 247, 0.4);
  color: #a855f7;
}
.tutorial-card {
  cursor: pointer;
  transition: transform 0.25s ease, box-shadow 0.25s ease;
}
.tutorial-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 8px 25px rgba(0,0,0,0.3);
}
</style>
