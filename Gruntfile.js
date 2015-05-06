/*global module:false*/
module.exports = function(grunt) {

  grunt.initConfig({
    pkg: grunt.file.readJSON('package.json'),
    cfg: {
      dist: 'dist/',
      static: 'static/'
    },
    clean: {
      static: [
        '<%= cfg.static %>'
      ]
    },
    copy: {
      static: {
        files: [
          {
            expand: true,
            cwd: 'img/',
            src: '**',
            dest: '<%= cfg.static %>img/'
          },
          {
            expand: true,
            cwd: 'bower_components/jquery-mousewheel/',
            src: 'jquery.mousewheel.min.js',
            dest: '<%= cfg.static %>js/'
          },
          {
            expand: true,
            cwd: 'bower_components/jquery-cookie/',
            src: 'jquery.cookie.js',
            dest: '<%= cfg.static %>js/'
          },
          {
            expand: true,
            cwd: 'bower_components/sweetalert/lib/',
            src: 'sweet-alert.css',
            dest: '<%= cfg.static %>css/'
          },
          {
            expand: true,
            cwd: 'bower_components/sweetalert/lib/',
            src: 'sweet-alert.min.js',
            dest: '<%= cfg.static %>js/'
          }
        ]
      }
    },
    less: {
      static: {
        files: {
          '<%= cfg.static %>css/common.css': 'less/common.less',
          '<%= cfg.static %>css/mobile.css': 'less/mobile.less'
        }
      }
    },
    uglify: {
      cookie: {
        src: 'bower_components/jquery-cookie/jquery.cookie.js',
        dest: '<%= cfg.static %>js/jquery.cookie.min.js'
      }
    },
    cssmin: {
      all: {
        files: [{
          expand: true,
          cwd: '<%= cfg.static %>css',
          src: ['*.css', '!*.min.css'],
          dest: '<%= cfg.static %>css',
          ext: '.min.css'
        }]
      }
    },
    jshint: {
      options: {
        curly: true,
        eqeqeq: true,
        immed: true,
        latedef: true,
        newcap: true,
        noarg: true,
        sub: true,
        undef: true,
        unused: true,
        boss: true,
        eqnull: true,
        globals: {
          jQuery: true
        }
      },
      gruntfile: {
        src: 'Gruntfile.js'
      }
    },
    watch: {
      gruntfile: {
        files: '<%= jshint.gruntfile.src %>',
        tasks: ['jshint:gruntfile']
      },
      less: {
        files: 'less/*',
        tasks: ['less:static']
      }
    }
  });

  grunt.loadNpmTasks('grunt-contrib-clean');
  grunt.loadNpmTasks('grunt-contrib-copy');
  grunt.loadNpmTasks('grunt-contrib-less');
  grunt.loadNpmTasks('grunt-contrib-concat');
  grunt.loadNpmTasks('grunt-contrib-uglify');
  grunt.loadNpmTasks('grunt-contrib-cssmin');
  grunt.loadNpmTasks('grunt-contrib-jshint');
  grunt.loadNpmTasks('grunt-contrib-watch');

  grunt.registerTask('default', ['copy', 'less', 'jshint', 'uglify', 'cssmin']);

};
