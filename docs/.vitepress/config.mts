import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Laravel Brain',
  description: "Visualize your Laravel application's full request lifecycle as an interactive graph.",
  lang: 'en-US',
  lastUpdated: true,

  head: [
    ['link', { rel: 'icon', type: 'image/png', href: '/favicon.png' }],
  ],

  themeConfig: {
    logo: '/logo.png',

    nav: [
      { text: 'Guide', link: '/installation' },
      { text: 'Usage', link: '/usage' },
      { text: 'How It Works', link: '/how-it-works' },
    ],

    sidebar: [
      {
        text: 'Documentation',
        items: [
          { text: 'Introduction', link: '/' },
          { text: 'Installation', link: '/installation' },
          { text: 'Usage', link: '/usage' },
          { text: 'How It Works', link: '/how-it-works' },
        ],
      },
    ],

    socialLinks: [
      { icon: 'github', link: 'https://github.com/LaraMint/laravel-brain' },
    ],

    search: {
      provider: 'local',
    },

    outline: {
      level: [2, 3],
      label: 'On this page',
    },

    footer: {
      message: 'Released under the MIT License. Laravel Brain is an independent, community-maintained package and isn\'t affiliated with Laravel or Laravel Holdings Inc.',
      copyright: 'Copyright © 2026 LaraMint',
    },
  },
})
